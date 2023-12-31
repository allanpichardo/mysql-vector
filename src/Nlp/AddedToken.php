<?php

namespace MHz\MysqlVector\Nlp;

class AddedToken
{
    public function __construct(array $config) {
        $this->content = $config['content'];
        $this->id = $config['id'];
        $this->singleWord = $config['single_word'] ?? false;
        $this->lstrip = $config['lstrip'] ?? false;
        $this->rstrip = $config['rstrip'] ?? false;
        $this->special = $config['special'] ?? false;
        $this->normalized = $config['normalized'] ?? null;
    }
}