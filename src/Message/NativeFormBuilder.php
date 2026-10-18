<?php
declare(strict_types=1);
namespace MVO\Message;

/** Native forms with portable custom answers or explicit Android input fields, distinct from Galaxy Flows. */
final class NativeFormBuilder
{
    /** @param array<string,mixed> $content
     * @return array<string,mixed> */
    public static function build(array $content): array
    {
        self::fields($content, ['type','text','cta','title','footer','steps','platform',
            'contextInfo','quote','forward','mentions','pushPriority','ephemeralExpiration','viewOnce'], 'form');
        if (($content['type'] ?? 'form') !== 'form') throw new \InvalidArgumentException('Native forms require type form');
        $text = self::text($content['text'] ?? null, 'form text', 65536);
        $cta = self::button($content['cta'] ?? null, 'form cta');
        $platform = array_key_exists('platform', $content) ? $content['platform'] : 'portable';
        if (!in_array($platform, ['portable','android'], true)) throw new \InvalidArgumentException('Form platform must be portable or android');
        $steps = $content['steps'] ?? null;
        if (!is_array($steps) || !array_is_list($steps) || count($steps) < 1 || count($steps) > 3) {
            throw new \InvalidArgumentException('Native forms require 1 to 3 steps');
        }
        $components = []; $ids = []; $stepIds = [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) throw new \InvalidArgumentException('Form step '.$index.' must be an object');
            $kind = array_key_exists('kind', $step) ? $step['kind'] : 'choice';
            if (!in_array($kind, ['choice','input'], true)) throw new \InvalidArgumentException('Form step kind must be choice or input');
            self::fields($step, $kind === 'input' ? ($platform === 'portable'
                ? ['id','label','kind','fields','button','cancel_option'] : ['id','label','kind','fields','button'])
                : ['id','label','kind','selection','options','allow_custom_answer'], 'form step');
            $id = self::text($step['id'] ?? null, 'form step id', 128);
            if ($platform === 'portable' && $kind === 'choice' && $id === '__proto__') {
                throw new \InvalidArgumentException('Portable form response ID __proto__ is reserved by the Web client');
            }
            if (in_array($id, $ids, true)) throw new \InvalidArgumentException('Duplicate form step ID '.$id);
            $ids[] = $id;
            $label = self::text($step['label'] ?? null, 'form step label', 1024);
            if ($kind === 'input') {
                $cancel = null;
                if ($platform === 'portable') {
                    $cancel = $step['cancel_option'] ?? null;
                    if (!is_array($cancel)) throw new \InvalidArgumentException('Portable input steps require an explicit cancel_option with id and title');
                    self::fields($cancel, ['id','title'], 'input cancel option');
                    $cancel = ['id'=>self::text($cancel['id'] ?? null, 'input cancel option id', 128),
                        'title'=>self::text($cancel['title'] ?? null, 'input cancel option title', 1024)];
                }
                $fields = $step['fields'] ?? null;
                if (!is_array($fields) || !array_is_list($fields) || count($fields) < 1 || count($fields) > 5) {
                    throw new \InvalidArgumentException('Input steps require 1 to 5 fields');
                }
                $inputs = [];
                foreach ($fields as $field) {
                    if (!is_array($field)) throw new \InvalidArgumentException('Input field must be an object');
                    self::fields($field, ['id','label','type','required','default'], 'input field');
                    $fieldId = self::text($field['id'] ?? null, 'input field id', 128);
                    if ($platform === 'portable' && $fieldId === '__proto__') {
                        throw new \InvalidArgumentException('Portable form response ID __proto__ is reserved by the Web client');
                    }
                    if (in_array($fieldId, $ids, true)) throw new \InvalidArgumentException('Duplicate form field ID '.$fieldId);
                    $ids[] = $fieldId;
                    if (($field['type'] ?? null) !== 'text') throw new \InvalidArgumentException('Native input fields support type text only');
                    if (!array_key_exists('required', $field) || !is_bool($field['required'])) throw new \InvalidArgumentException('Input field required must be boolean');
                    $input = ['id'=>$fieldId,'label'=>self::text($field['label'] ?? null, 'input field label', 1024),
                        'type'=>'text','required'=>$field['required']];
                    if (array_key_exists('default', $field)) {
                        $input['default'] = self::defaultText($field['default']);
                        if ($platform === 'portable' && $input['default'] !== '') {
                            throw new \InvalidArgumentException('Portable input defaults must be empty; custom answers do not support prefilled text');
                        }
                    }
                    $inputs[] = $input;
                }
                $button = array_key_exists('button', $step) ? self::button($step['button'], 'input button') : $cta;
                if ($platform === 'android') {
                    $stepIds[] = $id;
                    $components[] = ['id'=>$id,'component'=>'InputFields','label'=>$label,'title'=>$label,'fields'=>$inputs,'button'=>$button];
                } else {
                    // Each field is a real choice between cancellation and a custom text answer.
                    // Required text is validated by the application; cancellation remains a valid choice.
                    foreach ($inputs as $input) {
                        $stepIds[] = $input['id'];
                        $fieldLabel = $label."\n".$input['label'];
                        $components[] = ['id'=>$input['id'],'component'=>'ChoicePicker','variant'=>'mutuallyExclusive',
                            'label'=>$fieldLabel,'title'=>$fieldLabel,'button'=>$button,'options'=>[$cancel],'allow_custom_answer'=>true];
                    }
                }
                continue;
            }
            $variant = match ($step['selection'] ?? null) {
                'single'=>'mutuallyExclusive', 'multiple'=>'multipleSelection',
                default=>throw new \InvalidArgumentException('Form selection must be single or multiple'),
            };
            $custom = $step['allow_custom_answer'] ?? false;
            if (array_key_exists('allow_custom_answer', $step) && !is_bool($step['allow_custom_answer'])) {
                throw new \InvalidArgumentException('allow_custom_answer must be boolean');
            }
            $options = $step['options'] ?? null;
            if (!is_array($options) || !array_is_list($options) || count($options) < 1 || count($options) > 5) {
                throw new \InvalidArgumentException('Each form step requires 1 to 5 options');
            }
            $choices = []; $optionIds = [];
            foreach ($options as $option) {
                if (!is_array($option)) throw new \InvalidArgumentException('Form option must be an object');
                self::fields($option, ['id','title'], 'form option');
                $optionId = self::text($option['id'] ?? null, 'form option id', 128);
                if (in_array($optionId, $optionIds, true)) throw new \InvalidArgumentException('Duplicate form option ID '.$optionId);
                $optionIds[] = $optionId;
                $choices[] = ['id'=>$optionId,'title'=>self::text($option['title'] ?? null, 'form option title', 1024)];
            }
            $stepIds[] = $id;
            $components[] = ['id'=>$id,'component'=>'ChoicePicker','variant'=>$variant,
                'label'=>$label,'title'=>$label,'button'=>$cta,'options'=>$choices,'allow_custom_answer'=>$custom];
        }
        if (count($stepIds) > 3) throw new \InvalidArgumentException('Native forms allow at most 3 rendered steps after portable input expansion');
        // Keep the internal graph root separate from caller-owned response field IDs.
        $root = '_mvo_form';
        while (in_array($root, $ids, true)) $root .= '_';
        array_unshift($components, ['id'=>$root,'component'=>'form','children'=>$stepIds]);
        $payload = ['version'=>'2','payload'=>['type'=>'a2ui_form','data'=>['root'=>$root,'components'=>$components]]];
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $interactive = ['body'=>['text'=>$text],'nativeFlowMessage'=>[
            'buttons'=>[['name'=>'form_message','buttonParamsJson'=>json_encode(['display_text'=>$cta], $flags)]],
            'messageParamsJson'=>json_encode($payload, $flags),'messageVersion'=>1,
        ]];
        if (array_key_exists('title', $content)) $interactive['header'] = [
            'title'=>self::text($content['title'], 'form title', 1024),'hasMediaAttachment'=>false,
        ];
        if (array_key_exists('footer', $content)) $interactive['footer'] = ['text'=>self::text($content['footer'], 'form footer', 1024)];
        return ['interactiveMessage'=>$interactive];
    }

    /** @param array<array-key,mixed> $value
     * @param list<string> $allowed */
    private static function fields(array $value, array $allowed, string $label): void
    {
        foreach ($value as $key => $_) {
            if (!in_array($key, $allowed, true)) throw new \InvalidArgumentException('Unknown '.$label.' field '.$key);
        }
    }

    private static function text(mixed $value, string $label, int $maxBytes): string
    {
        if (!is_string($value) || trim($value) === '' || preg_match('//u', $value) !== 1 || strlen($value) > $maxBytes) {
            throw new \InvalidArgumentException($label.' must contain 1 to '.$maxBytes.' UTF-8 bytes');
        }
        return $value;
    }

    private static function button(mixed $value, string $label): string
    {
        $value = self::text($value, $label, 120);
        if (preg_match_all('/./us', $value) > 30) throw new \InvalidArgumentException($label.' cannot exceed 30 Unicode characters');
        return $value;
    }

    private static function defaultText(mixed $value): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) throw new \InvalidArgumentException('Input default must be UTF-8 text');
        // The Android parser measures Java String.length(), including two units for supplementary characters.
        $characters = preg_match_all('/./us', $value);
        $supplementary = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);
        if ($characters === false || $supplementary === false || $characters + $supplementary > 128) {
            throw new \InvalidArgumentException('Input default cannot exceed 128 UTF-16 code units');
        }
        return $value;
    }
}
