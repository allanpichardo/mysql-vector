<?php

namespace MHz\MysqlVector\Nlp;

class BertTokenizer
{
    private bool $warnedAboutChatTemplate = false;
    private string $defaultChatTemplate = "{% for message in messages %}{{'<|im_start|>' + message['role'] + '\n' + message['content'] + '<|im_end|>' + '\n'}}{% endfor %}{% if add_generation_prompt %}{{ '<|im_start|>assistant\n' }}{% endif %}";
    protected array $tokenizerConfig;
    private BertNormalizer $normalizer;
    private BertPreTokenizer $preTokenizer;
    private WordpieceTokenizer $model;
    private TemplateProcessing $postProcessor;
    private WordPieceDecoder $decoder;
    private array $specialTokens;
    private array $allSpecialIds;
    private array $addedTokens;
    private ?string $addedTokensRegex;
    /**
     * @var array|mixed
     */
    private mixed $additionalSpecialTokens;
    private $maskToken;
    /**
     * @var int|mixed
     */
    private mixed $maskTokenId;
    private $padToken;
    /**
     * @var int|mixed
     */
    private mixed $padTokenId;
    private $sepToken;
    /**
     * @var int|mixed
     */
    private mixed $sepTokenId;
    private $unkToken;
    /**
     * @var int|mixed
     */
    private mixed $unkTokenId;
    /**
     * @var int|mixed
     */
    public mixed $modelMaxLength;
    /**
     * @var false|mixed
     */
    private mixed $removeSpace;
    /**
     * @var mixed|true
     */
    private mixed $cleanUpTokenizationSpaces;
    /**
     * @var false|mixed
     */
    private mixed $doLowercaseAndRemoveAccent;
    private string $paddingSide;
    /**
     * @var false
     */
    private bool $legacy;
    /**
     * @var mixed|null
     */
    private mixed $chatTemplate;
    private array $compiledTemplateCache;

    public function __construct(array $tokenizerJSON, array $tokenizerConfig) {
        $this->tokenizerConfig = $tokenizerConfig;

        $this->normalizer = new BertNormalizer($tokenizerJSON['normalizer']);
        $this->preTokenizer = new BertPreTokenizer($tokenizerJSON['pre_tokenizer']);

        $this->model = new WordpieceTokenizer($tokenizerJSON['model']);
        $this->postProcessor = new TemplateProcessing($tokenizerJSON['post_processor']);

        $this->decoder = new WordPieceDecoder($tokenizerJSON['decoder']);

        $this->specialTokens = [];
        $this->allSpecialIds = [];

        $this->addedTokens = [];
        foreach ($tokenizerJSON['added_tokens'] as $addedToken) {
            $token = new AddedToken($addedToken);
            $this->addedTokens[] = $token;

            $this->model->tokensToIds[$token->content] = $token->id;
            $this->model->vocab[$token->id] = $token->content;

            if($token->special) {
                $this->specialTokens[] = $token->content;
                $this->allSpecialIds[] = $token->id;
            }
        }

        //Update additional special tokens
        $this->additionalSpecialTokens = $tokenizerConfig['additional_special_tokens'] ?? [];
        $this->specialTokens = array_merge($this->specialTokens, $this->additionalSpecialTokens);
        $this->specialTokens = array_unique($this->specialTokens);

        if(!empty($this->decoder)) {
            // Slight hack, but it prevents code duplication
            $this->decoder->addedTokens = $this->addedTokens;

            // Another slight hack to add `end_of_word_suffix` (if present) to the decoder
            // This is needed for cases where BPE model and ByteLevel decoder are used
            // For more information, see https://github.com/xenova/transformers.js/issues/74
            // TODO: save this to the decoder when exporting?
            $this->decoder->endOfWordSuffix = $this->model->endOfWordSuffix;
        }

        if (count($this->addedTokens) > 0) {
            $regexParts = array_map(function ($token) {
                $lstrip = $token->lstrip ? '\\s*' : '';
                $rstrip = $token->rstrip ? '\\s*' : '';
                $content = preg_quote($token->content, '/');

                return $lstrip . '(' . $content . ')' . $rstrip;
            }, $this->addedTokens);

            $this->addedTokensRegex = '/' . implode('|', $regexParts) . '/';
        } else {
            $this->addedTokensRegex = null;
        }

        $this->maskToken = $this->getToken('mask_token');
        $this->maskTokenId = $this->model->tokensToIds[$this->maskToken];

        $this->padToken = $this->getToken('pad_token', 'eos_token');
        $this->padTokenId = $this->model->tokensToIds[$this->padToken];

        $this->sepToken = $this->getToken('sep_token');
        $this->sepTokenId = $this->model->tokensToIds[$this->sepToken];

        $this->unkToken = $this->getToken('unk_token');
        $this->unkTokenId = $this->model->tokensToIds[$this->unkToken];

        $this->modelMaxLength = $tokenizerConfig['model_max_length'] ?? 512;

        $this->removeSpace = $tokenizerConfig['remove_space'] ?? false;

        $this->cleanUpTokenizationSpaces = $tokenizerConfig['clean_up_tokenization_spaces'] ?? true;
        $this->doLowercaseAndRemoveAccent = $tokenizerConfig['do_lowercase_and_remove_accent'] ?? false;

        $this->paddingSide = 'right';

        $this->legacy = false;

        $this->chatTemplate = $tokenizerConfig['chat_template'] ?? null;
        $this->compiledTemplateCache = [];
    }

    /**
     * Returns the value of the first matching key in the tokenizer config object.
     * @param ...$keys string keys to search for.
     * @return mixed|null The value of the first matching key, or null if no key is found.
     * @throws \Exception If an object is found for a matching key and its __type property is not 'AddedToken'.
     */
    public function getToken(...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!isset($this->tokenizerConfig[$key])) {
                continue;
            }

            $item = $this->tokenizerConfig[$key];

            if (is_array($item)) {
                if (isset($item['__type']) && $item['__type'] === 'AddedToken') {
                    return $item['content'];
                } else {
                    throw new \Exception("Unknown token: " . json_encode($item));
                }
            } else {
                return $item;
            }
        }
        return null;
    }

    /**
     * This function can be overridden by a subclass to apply additional preprocessing steps to the inputs.
     * @param $inputs array The inputs to preprocess.
     * @return array The modified inputs.
     * @throws \Exception If input ids are not an array.
     */
    public function prepareModelInputs($inputs): array
    {
        return $this->addTokenTypes($inputs);
    }

    /**
     * Helper method for adding `token_type_ids` to model inputs.
     *
     * @param array $inputs An associative array containing the input ids and attention mask.
     * @return array The prepared inputs array.
     * @throws \Exception If input ids are not an array.
     */
    private function addTokenTypes(array $inputs): array
    {
        if (!is_array($inputs['input_ids'])) {
            throw new \Exception('Input ids must be an array');
        }

        if (is_array($inputs['input_ids'][0])) {
            // Input is batched, so batch the token_type_ids as well
            $inputs['token_type_ids'] = array_map(function($x) {
                return array_fill(0, count($x), 0);
            }, $inputs['input_ids']);
        } else {
            // Single input
            $inputs['token_type_ids'] = array_fill(0, count($inputs['input_ids']), 0);
        }

        return $inputs;
    }

    public function call(string|array $text, array $options = [
        'text_pair' => null,
        'add_special_tokens' => true,
        'padding' => false,
        'truncation' => null,
        'max_length' => null,
        'return_tensor' => false, // Different to HF
    ]): array
    {
        $textPair = $options['text_pair'] ?? null;
        $addSpecialTokens = $options['add_special_tokens'] ?? true;
        $padding = $options['padding'] ?? false;
        $truncation = $options['truncation'] ?? null;
        $maxLength = $options['max_length'] ?? null;
        $returnTensor = $options['return_tensor'] ?? true;

        $tokens = [];

        if(is_array($text)) {
            if(count($text) === 0) {
                throw new \Exception('Input is empty');
            }

            if($textPair !== null) {
                if(!is_array($textPair)) {
                    throw new \Exception('`text_pair` must be an array');
                } else if(count($text) !== count($textPair)) {
                    throw new \Exception('`text` and `text_pair` must have the same length');
                }

                foreach ($text as $i => $t) {
                    $tokens[] = $this->encode($t, $textPair[$i], $options);
                }
            } else {
                foreach ($text as $x) {
                    $tokens[] = $this->encode($x, null, $options);
                }
            }
        } else {
            if($text === null) {
                throw new \Exception('text may not be null');
            }

            if(is_array($textPair)) {
                throw new \Exception('When specifying `text_pair`, since `text` is a string, `text_pair` must also be a string (i.e., not an array).' );
            }

            $tokens[] = $this->encode($text, $textPair, $options);
        }

        if($maxLength === null) {
            if($padding === 'max_length') {
                $maxLength = $this->modelMaxLength;
            } else {
                $maxLength = max(array_map(function($x) { return count($x); }, $tokens));
            }
        }

        $maxLength = min($maxLength, $this->modelMaxLength);

        $attentionMask = [];
        if ($padding || $truncation) {
            for ($i = 0; $i < count($tokens); ++$i) {
                if (count($tokens[$i]) === $maxLength) {
                    $attentionMask[] = array_fill(0, count($tokens[$i]), 1);
                } elseif (count($tokens[$i]) > $maxLength) {
                    // Possibly truncate
                    if ($truncation) {
                        $tokens[$i] = substr($tokens[$i], 0, $maxLength);
                    }
                    $attentionMask[] = array_fill(0, count($tokens[$i]), 1);
                } else {
                    // Token length < max_length
                    $diff = $maxLength - count($tokens[$i]);
                    if ($padding) {
                        if ($this->paddingSide === 'right') {
                            $attentionMask[] = array_merge(array_fill(0, count($tokens[$i]), 1), array_fill(0, $diff, 0));
                            for ($j = 0; $j < $diff; $j++) {
                                $tokens[$i][] = $this->padTokenId;
                            }
                        } else {
                            // Padding on the left
                            $attentionMask[] = array_merge(array_fill(0, $diff, 0), array_fill(0, count($tokens[$i]), 1));
                            $paddingTokens = array_fill(0, $diff, $this->padTokenId);
                            foreach ($paddingTokens as $paddingToken) {
                                array_unshift($tokens[$i], $paddingToken);
                            }
                        }
                    } else {
                        $attentionMask[] = array_fill(0, count($tokens[$i]), 1);
                    }
                }
            }
        } else {
            foreach ($tokens as $token) {
                $attentionMask[] = array_fill(0, count($token), 1);
            }
        }

        // Not going to bother with the `return_tensors` option for now
        // todo: add `return_tensors` option

        if(!is_array($text)) {
            $tokens = $tokens[0];
            $attentionMask = $attentionMask[0];
        }

        $modelInputs = [
            'input_ids' => $tokens,
            'attention_mask' => $attentionMask,
        ];

        return $this->prepareModelInputs($modelInputs);
    }

    /**
     * Helper function to remove accents from a string.
     *
     * @param string $text The text to remove accents from.
     * @return string The text with accents removed.
     */
    private function removeAccents(string $text): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    }

    /**
     * Helper function to lowercase a string and remove accents.
     *
     * @param string $text The text to lowercase and remove accents from.
     * @return string The lowercased text with accents removed.
     */
    private function lowercaseAndRemoveAccent(string $text): string
    {
        return $this->removeAccents(mb_strtolower($text, 'UTF-8'));
    }

    /**
     * Encodes a single text using the preprocessor pipeline of the tokenizer
     * @param $text string|null the text to encode
     * @return array|null the encoded tokens
     * @throws \Exception
     */
    private function encodeText(?string $text): ?array {
        if ($text === null) return null;

        // Split text based on added tokens regex, if available
        $sections = $this->addedTokensRegex ? preg_split($this->addedTokensRegex, $text) : [$text];
        $sections = array_filter($sections); // Filter out empty strings

        $tokens = [];
        foreach ($sections as $sectionIndex => $x) {
            $postFilter = array_filter($this->addedTokens, function($t) use ($x) {
                return $t->content === $x;
            });
            $addedToken = reset($postFilter);
            if (!empty($addedToken)) {
                $tokens[] = $x;
            } else {
                // Process the section
                if ($this->removeSpace === true) {
                    $x = preg_replace('/\s+/', ' ', trim($x));
                }
                if ($this->doLowercaseAndRemoveAccent) {
                    $x = $this->lowercaseAndRemoveAccent($x); // Implement this method
                }

                if ($this->normalizer !== null) {
                    $x = $this->normalizer->normalize($x); // Assuming normalizer is an object with a normalize method
                }

                $sectionTokens = ($this->preTokenizer !== null) ? $this->preTokenizer->preTokenize($x) : [$x];
                $modelTokens = $this->model->encode($sectionTokens); // Assuming model is an object with an encode method

                $tokens = array_merge($tokens, $modelTokens);
            }
        }

        return $tokens;
    }

    /**
     * Encodes a single text or a pair of texts using the model's tokenizer.
     * @param $text string the text to encode
     * @param $textPair string|null the second text to encode
     * @param $options array the options for encoding
     * @return array
     * @throws \Exception
     */
    public function encode(string $text, string $textPair = null, array $options = []): array {
        $addSpecialTokens = $options['add_special_tokens'] ?? true;

        $tokens = $this->encodeText($text);
        $tokens2 = !empty($textPair) ? $this->encodeText($textPair) : [];

        // TODO: Improve `add_special_tokens` and ensure correctness
        $combinedTokens = ($this->postProcessor !== null && $addSpecialTokens)
            ? $this->postProcessor->postProcess($tokens, $tokens2)
            : array_merge($tokens ?? [], $tokens2 ?? []);

        $ids = $this->model->convertTokensToIds($combinedTokens);
        return $ids;
    }

    /**
     * Decode a batch of tokenized sequences.
     * @param $batch array list of tokenized input sequences.
     * @param $decodeArgs array Optional object with decoding arguments
     * @return array List of decoded sequences.
     */
    public function batchDecode($batch, $decodeArgs = []) {
        $decoded = [];
        foreach ($batch as $sequence) {
            $decoded[] = $this->decode($sequence, $decodeArgs); // Assuming decode is a method in this class
        }
        return $decoded;
    }

    /**
     * Decodes a sequence of token IDs back to a string.
     *
     * @param array|int[] $tokenIds List of token IDs to decode.
     * @param array $decodeArgs {
     *     Optional. Arguments for decoding.
     *
     *     @type bool $skipSpecialTokens If true, special tokens are removed from the output string.
     *     @type bool $cleanUpTokenizationSpaces If true, spaces before punctuations are removed.
     * }
     * @return string The decoded string.
     * @throws \Exception If `tokenIds` is not a non-empty array of integers.
     */
    public function decode(array $tokenIds, array $decodeArgs = []): string
    {
        if (!is_array($tokenIds) || count($tokenIds) === 0 || !$this->isIntegralNumber($tokenIds[0])) {
            throw new \Exception("tokenIds must be a non-empty array of integers.");
        }

        return $this->decodeSingle($tokenIds, $decodeArgs); // Assuming 'decodeSingle' is a method defined for decoding
    }

    /**
     * Check if a value is an integer.
     *
     * @param mixed $x The value to check.
     * @return bool True if the value is an integer, false otherwise.
     */
    private function isIntegralNumber(mixed $x): bool
    {
        return is_int($x) || is_string($x) && ctype_digit($x);
    }

    /**
     * Decode a single list of token ids to a string.
     *
     * @param array $tokenIds List of token ids to decode.
     * @param array $decodeArgs {
     *     Optional arguments for decoding.
     *
     * @type bool $skipSpecialTokens Whether to skip special tokens during decoding.
     * @type bool|null $cleanUpTokenizationSpaces Whether to clean up tokenization spaces during decoding.
     * }
     * @return string The decoded string.
     * @throws \Exception
     */
    public function decodeSingle(array $tokenIds, array $decodeArgs = []): string
    {
        $skipSpecialTokens = $decodeArgs['skip_special_tokens'] ?? false;
        $cleanUpTokenizationSpaces = $decodeArgs['clean_up_tokenization_spaces'] ?? $this->cleanUpTokenizationSpaces ?? true;

        $tokens = $this->model->convertIdsToTokens($tokenIds);

        if ($skipSpecialTokens) {
            $tokens = array_filter($tokens, function($token) {
                return !in_array($token, $this->specialTokens);
            });
        }

        $decoded = $this->decoder ? $this->decoder->decode($tokens) : implode(' ', $tokens);

        if ($this->decoder && $this->decoder->endOfWordSuffix) {
            $decoded = str_replace($this->decoder->endOfWordSuffix, ' ', $decoded);
            if ($skipSpecialTokens) {
                $decoded = trim($decoded);
            }
        }

        if ($cleanUpTokenizationSpaces) {
            $decoded = $this->cleanUpTokenization($decoded);
        }

        return $decoded;
    }

    /**
     * Clean up a list of simple English tokenization artifacts like spaces before punctuations and abbreviated forms.
     *
     * @param string $text The text to clean up.
     * @return string The cleaned up text.
     */
    private function cleanUpTokenization(string $text): string
    {
        $patterns = ['/ \./', '/ \?/', '/ \!/', '/ ,/', "/ ' /", "/ n't/", "/ 'm/", "/ 's/", "/ 've/", "/ 're/"];
        $replacements = ['.', '?', '!', ',', "'", "n't", "'m", "'s", "'ve", "'re"];

        return preg_replace($patterns, $replacements, $text);
    }

    /**
     * Get the default chat template.
     *
     * @return mixed The default chat template.
     */
    public function getDefaultChatTemplate(): mixed
    {
        if (!$this->warnedAboutChatTemplate) {
            // Log the warning here (use your preferred logging method)
            error_log(
                "No chat template is defined for this tokenizer - using a default chat template " .
                "that implements the ChatML format. If the default is not appropriate for " .
                "your model, please set `tokenizer.chat_template` to an appropriate template. " .
                "See https://huggingface.co/docs/transformers/main/chat_templating for more information."
            );
            $this->warnedAboutChatTemplate = true;
        }

        return $this->defaultChatTemplate;
    }
}