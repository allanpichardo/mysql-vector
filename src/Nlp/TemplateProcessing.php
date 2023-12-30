<?php

namespace MHz\MysqlVector\Nlp;

use MHz\MysqlVector\Nlp\PostProcessor;

class TemplateProcessing extends PostProcessor
{
    private mixed $single;
    private mixed $pair;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->single = $config['single'] ?? [];
        $this->pair = $config['pair'] ?? [];
    }

    /**
     * Replaces special tokens in the template with actual tokens.
     * @param array $tokens The list of tokens for the first sequence.
     * @param array ...$args The list of tokens for the second sequence. Optional.
     * @return array The list of tokens with replaced special tokens.
     */
    public function postProcess(array $tokens, ...$args): array {
        $tokensPair = $args[0] ?? null;
        $type = is_null($tokensPair) ? $this->single : $this->pair;

        $toReturn = [];
        foreach ($type as $item) {
            if (isset($item['SpecialToken'])) {
                $toReturn[] = $item['SpecialToken']['id'];

            } elseif (isset($item['Sequence'])) {
                if ($item['Sequence']['id'] === 'A') {
                    $toReturn = array_merge($toReturn, $tokens);

                } elseif ($item['Sequence']['id'] === 'B') {
                    $toReturn = array_merge($toReturn, $tokensPair);
                }
            }
        }
        return $toReturn;
    }
}