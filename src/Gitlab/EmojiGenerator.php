<?php


namespace TheCodingMachine\WashingMachine\Gitlab;

/**
 * Class in charge of choosing the appropriate Emoji.
 */
class EmojiGenerator
{
    private $emojiMap;

    public function __construct(array $emojiMap)
    {
        $this->emojiMap = $emojiMap;
    }

    public function getEmoji(float $score) : string
    {
        $lastValue = '';
        foreach ($this->emojiMap as $priority => $value) {
            if ($priority > $score) {
                return $lastValue;
            }
            $lastValue = $value;
        }
        return $lastValue;
    }

    public static function createCrapScoreEmojiGenerator() : self
    {
        $emojiMap = [
            1 => ':innocent:',
            31 => ':neutral_face:',
            50 => ':sweat:',
            80 => ':slight_frown:',
            120 => ':sob:',
            300 => ':scream:',
            600 => ':radioactive:',
            900 => ':skull_crossbones:'
        ];
        return new self($emojiMap);
    }
}
