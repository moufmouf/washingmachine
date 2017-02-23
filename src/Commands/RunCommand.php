<?php
namespace TheCodingMachine\WashingMachine\Commands;

use Gitlab\Client;
use Gitlab\Exception\RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TheCodingMachine\WashingMachine\Clover\CloverFile;
use TheCodingMachine\WashingMachine\Clover\Crap4JFile;
use TheCodingMachine\WashingMachine\Clover\CrapMethodFetcherInterface;
use TheCodingMachine\WashingMachine\Clover\CrapMethodMerger;
use TheCodingMachine\WashingMachine\Clover\DiffService;
use TheCodingMachine\WashingMachine\Clover\EmptyCloverFile;
use TheCodingMachine\WashingMachine\Gitlab\BuildNotFoundException;
use TheCodingMachine\WashingMachine\Gitlab\BuildService;
use TheCodingMachine\WashingMachine\Gitlab\MergeRequestNotFoundException;
use TheCodingMachine\WashingMachine\Gitlab\Message;
use TheCodingMachine\WashingMachine\Gitlab\SendCommentService;

class RunCommand extends Command
{

    protected function configure()
    {
        $this
            ->setName('run')
            ->setDescription('Analyses the coverage report files and upload the result to Gitlab')
            //->setHelp("This command allows you to create users...")
            ->addOption('clover',
                'c',
                InputOption::VALUE_REQUIRED,
                'The path to the clover.xml file generated by PHPUnit.',
                'clover.xml')
            ->addOption('crap4j',
                'j',
                InputOption::VALUE_REQUIRED,
                'The path to the crap4j.xml file generated by PHPUnit.',
                'crap4j.xml')
            ->addOption('gitlab-url',
                'u',
                InputOption::VALUE_REQUIRED,
                'The Gitlab URL. If not specified, it is deduced from the CI_BUILD_REPO environment variable.',
                null)
            ->addOption('gitlab-api-token',
                't',
                InputOption::VALUE_REQUIRED,
                'The Gitlab API token. If not specified, it is fetched from the GITLAB_API_TOKEN environment variable.',
                null)
            /*->addOption('gitlab-project-id',
                'p',
                InputOption::VALUE_REQUIRED,
                'The Gitlab project ID. If not specified, it is fetched from the CI_PROJECT_ID environment variable.',
                null)*/
            ->addOption('gitlab-project-name',
                'p',
                InputOption::VALUE_REQUIRED,
                'The Gitlab project name (in the form "group/name"). If not specified, it is deduced from the CI_PROJECT_DIR environment variable.',
                null)
            ->addOption('gitlab-build-ref',
                'r',
                InputOption::VALUE_REQUIRED,
                'The Gitlab CI build reference. If not specified, it is deduced from the CI_BUILD_REF environment variable.',
                null)
            ->addOption('gitlab-build-id',
                'b',
                InputOption::VALUE_REQUIRED,
                'The Gitlab CI build id. If not specified, it is deduced from the CI_BUILD_ID environment variable.',
                null)
            ->addOption('file',
                'f',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Text file to be sent in the merge request comments (can be used multiple times).',
                [])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = new Config($input);

        $cloverFilePath = $config->getCloverFilePath();

        $cloverFile = null;
        if (file_exists($cloverFilePath)) {
            $cloverFile = CloverFile::fromFile($cloverFilePath, getcwd());
            //$output->writeln(sprintf('Code coverage: %.2f%%', $cloverFile->getCoveragePercentage() * 100));
        }

        $crap4JFilePath = $config->getCrap4JFilePath();

        $crap4jFile = null;
        if (file_exists($crap4JFilePath)) {
            $crap4jFile = Crap4JFile::fromFile($crap4JFilePath);
        }

        $files = $config->getFiles();

        $methodsProvider = null;
        $codeCoverageProvider = null;

        if ($cloverFile !== null && $crap4jFile !== null) {
            $methodsProvider = new CrapMethodMerger($cloverFile, $crap4jFile);
            $codeCoverageProvider = $cloverFile;
        } elseif ($cloverFile !== null) {
            $methodsProvider = $cloverFile;
            $codeCoverageProvider = $cloverFile;
        } elseif ($crap4jFile !== null) {
            $methodsProvider = $crap4jFile;
        } elseif (empty($files)) {
            throw new \RuntimeException('Could not find neither clover file, nor crap4j file for analysis nor files to send in comments. Nothing done. Searched paths: '.$cloverFilePath.' and '.$crap4JFilePath);
        }

        $gitlabApiToken = $config->getGitlabApiToken();

        $gitlabUrl = $config->getGitlabUrl();
        $gitlabApiUrl = $config->getGitlabApiUrl();


        /*$projectId = $input->getOption('gitlab-project-id');
        if ($projectId === null) {
            $projectId = getenv('CI_PROJECT_ID');
            if ($projectId === false) {
                throw new \RuntimeException('Could not find the Gitlab project ID in the "CI_PROJECT_ID" environment variable (usually set by Gitlab CI). Either set this environment variable or pass the ID via the --gitlab-project-id command line option.');
            }
        }*/

        $projectName = $config->getGitlabProjectName();

        $buildRef = $config->getGitlabBuildRef();

        $currentBranchName = $config->getCurrentBranchName();

        $client = new Client($gitlabApiUrl);
        $client->authenticate($gitlabApiToken);

        $diffService = new DiffService(1, 20);

        $sendCommentService = new SendCommentService($client, $diffService);

        // From CI_BUILD_REF, we can get the commit ( -> project -> build -> commit )
        // From the merge_requests API, we can get the list of commits for a single merge request
        // Hence, we can find the merge_request matching a build!

        $buildService = new BuildService($client);

        try {
            $mergeRequest = $buildService->findMergeRequestByBuildRef($projectName, $buildRef);


            try {
                list($previousCodeCoverageProvider, $previousMethodsProvider) = $this->getMeasuresFromBranch($buildService, $mergeRequest['target_project_id'], $mergeRequest['target_branch'], $cloverFilePath, $crap4JFilePath);
            } catch (RuntimeException $e) {
                if ($e->getCode() === 404) {
                    // We could not find a previous clover file in the master branch.
                    // Maybe this branch is the first to contain clover files?
                    // Let's deal with this by generating a fake "empty" clover file.
                    $previousCodeCoverageProvider = EmptyCloverFile::create();
                    $previousMethodsProvider = EmptyCloverFile::create();
                } else {
                    throw $e;
                }
            }

            $message = new Message();
            if ($codeCoverageProvider !== null) {
                $message->addCoverageMessage($codeCoverageProvider, $previousCodeCoverageProvider);
            } else {
                $output->writeln('Could not find clover file for code coverage analysis.');
            }
            if ($methodsProvider !== null) {
                $message->addDifferencesHtml($methodsProvider, $previousMethodsProvider, $diffService, $buildRef, $gitlabUrl, $projectName);
            } else {
                $output->writeln('Could not find clover file nor crap4j file for CRAP score analysis.');
            }

            foreach ($files as $file) {
                if (!file_exists($file)) {
                    $output->writeln('<error>Could not find file to send "'.$file.'". Skipping this file.</error>');
                    continue;
                }

                $message->addFile(new \SplFileInfo($file), $config->getGitlabUrl(), $projectName, $config->getGitlabBuildId());
            }

            $client->merge_requests->addComment($projectName, $mergeRequest['id'], (string) $message);

        } catch (MergeRequestNotFoundException $e) {
            // If there is no merge request attached to this build, let's skip the merge request comment. We can still make some comments on the commit itself!

            $output->writeln('It seems that this CI build is not part of a merge request. Skipping.');
        }

        try {
            $targetProjectId = $mergeRequest['target_project_id'] ?? $projectName;
            list($lastCommitCloverFile) = $this->getMeasuresFromBranch($buildService, $targetProjectId, $currentBranchName, $cloverFilePath, $crap4JFilePath);

            $sendCommentService->sendDifferencesCommentsInCommit($cloverFile, $lastCommitCloverFile, $projectName, $buildRef, $gitlabUrl);

            // TODO: open an issue if no merge request and failing build.
        } catch (BuildNotFoundException $e) {
            $output->writeln('Unable to find a previous build for this branch. Skipping adding comments inside the commit. '.$e->getMessage());
        }

    }

    /**
     * @param BuildService $buildService
     * @param string $projectName
     * @param string $targetBranch
     * @param string $cloverPath
     * @param string $crap4JPath
     * @return array First element: code coverage, second element: list of methods.
     */
    public function getMeasuresFromBranch(BuildService $buildService, string $projectName, string $targetBranch, string $cloverPath, string $crap4JPath) : array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'art').'.zip';

        $buildService->dumpArtifactFromBranch($projectName, $targetBranch, $tmpFile);
        $zipFile = new \ZipArchive();
        if ($zipFile->open($tmpFile)!==true) {
            throw new \RuntimeException('Invalid ZIP archive '.$tmpFile);
        }
        $cloverFileString = $zipFile->getFromName($cloverPath);

        $cloverFile = null;
        if ($cloverFileString !== false) {
            $cloverFile = CloverFile::fromString($cloverFileString, getcwd());
        }

        $crap4JString = $zipFile->getFromName($crap4JPath);

        $crap4JFile = null;
        if ($crap4JString !== false) {
            $crap4JFile = Crap4JFile::fromString($crap4JString);
        }

        $methodsProvider = null;
        $codeCoverageProvider = null;

        if ($cloverFile !== null && $crap4JFile !== null) {
            $methodsProvider = new CrapMethodMerger($cloverFile, $crap4JFile);
            $codeCoverageProvider = $cloverFile;
        } elseif ($cloverFile !== null) {
            $methodsProvider = $cloverFile;
            $codeCoverageProvider = $cloverFile;
        } elseif ($crap4JFile !== null) {
            $methodsProvider = $crap4JFile;
        } else {
            throw new \RuntimeException('Could not find nor clover file, neither crap4j file for analysis. Searched paths: '.$cloverFilePath.' and '.$crap4JFilePath);
        }

        return [$codeCoverageProvider, $methodsProvider];
    }
}

/*
=================ENV IN A PR CONTEXT =========================

CI_BUILD_TOKEN=xxxxxx
HOSTNAME=runner-9431b96d-project-428-concurrent-0
PHP_INI_DIR=/usr/local/etc/php
PHP_ASC_URL=https://secure.php.net/get/php-7.0.15.tar.xz.asc/from/this/mirror
CI_BUILD_BEFORE_SHA=7af13f8e3bd090c7c34750e4badfc66a5f0af110
CI_SERVER_VERSION=
CI_BUILD_ID=109
OLDPWD=/
PHP_CFLAGS=-fstack-protector-strong -fpic -fpie -O2
PHP_MD5=dca23412f3e3b3987e582091b751925d
CI_PROJECT_ID=428
PHPIZE_DEPS=autoconf 		file 		g++ 		gcc 		libc-dev 		make 		pkg-config 		re2c
PHP_URL=https://secure.php.net/get/php-7.0.15.tar.xz/from/this/mirror
CI_BUILD_REF_NAME=feature/js-ci
CI_BUILD_REF=7af13f8e3bd090c7c34750e4badfc66a5f0af110
PHP_LDFLAGS=-Wl,-O1 -Wl,--hash-style=both -pie
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
CI_BUILD_STAGE=test
CI_PROJECT_DIR=/builds/tcm-projects/uneo
PHP_CPPFLAGS=-fstack-protector-strong -fpic -fpie -O2
GPG_KEYS=1A4E8B7277C42E53DBA9C7B9BCAA30EA9C0D5763 6E4F6AB321FDC07F2C332E3AC2BF0BC433CFC8B3
PWD=/builds/tcm-projects/uneo
CI_DEBUG_TRACE=false
CI_SERVER_NAME=GitLab CI
XDEBUG_VERSION=2.5.0
GITLAB_CI=true
CI_SERVER_REVISION=
CI_BUILD_NAME=test:app
HOME=/root
SHLVL=1
PHP_SHA256=300364d57fc4a6176ff7d52d390ee870ab6e30df121026649f8e7e0b9657fe93
CI_SERVER=yes
CI=true
CI_BUILD_REPO=http://gitlab-ci-token:xxxxxx@git.thecodingmachine.com/tcm-projects/uneo.git
PHP_VERSION=7.0.15

===================ENV IN A COMMIT CONTEXT

CI_BUILD_TOKEN=xxxxxx
HOSTNAME=runner-9431b96d-project-447-concurrent-0
PHP_INI_DIR=/usr/local/etc/php
PHP_ASC_URL=https://secure.php.net/get/php-7.0.15.tar.xz.asc/from/this/mirror
CI_BUILD_BEFORE_SHA=42dd9686eafc2e8fb0a6b4d2c6785baec229c94a
CI_SERVER_VERSION=
CI_BUILD_ID=192
OLDPWD=/
PHP_CFLAGS=-fstack-protector-strong -fpic -fpie -O2
PHP_MD5=dca23412f3e3b3987e582091b751925d
CI_PROJECT_ID=447
GITLAB_API_TOKEN=xxxxxxxxxxxxxxchangedmanually
PHPIZE_DEPS=autoconf 		file 		g++ 		gcc 		libc-dev 		make 		pkg-config 		re2c
PHP_URL=https://secure.php.net/get/php-7.0.15.tar.xz/from/this/mirror
CI_BUILD_REF_NAME=master
CI_BUILD_REF=42dd9686eafc2e8fb0a6b4d2c6785baec229c94a
PHP_LDFLAGS=-Wl,-O1 -Wl,--hash-style=both -pie
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
CI_BUILD_STAGE=test
CI_PROJECT_DIR=/builds/dan/washing-test
PHP_CPPFLAGS=-fstack-protector-strong -fpic -fpie -O2
GPG_KEYS=1A4E8B7277C42E53DBA9C7B9BCAA30EA9C0D5763 6E4F6AB321FDC07F2C332E3AC2BF0BC433CFC8B3
PWD=/builds/dan/washing-test
CI_DEBUG_TRACE=false
CI_SERVER_NAME=GitLab CI
XDEBUG_VERSION=2.5.0
GITLAB_CI=true
CI_SERVER_REVISION=
CI_BUILD_NAME=test
HOME=/root
SHLVL=1
PHP_SHA256=300364d57fc4a6176ff7d52d390ee870ab6e30df121026649f8e7e0b9657fe93
CI_SERVER=yes
CI=true
CI_BUILD_REPO=http://gitlab-ci-token:xxxxxx@git.thecodingmachine.com/dan/washing-test.git
PHP_VERSION=7.0.15
*/