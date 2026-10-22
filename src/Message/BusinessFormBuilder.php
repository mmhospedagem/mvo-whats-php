<?php
declare(strict_types=1);
namespace MVO\Message;

/**
 * Authors a Business attachment-tray Galaxy CTA for an already published form.
 *
 * Pinned Android Business 2.26.42.70: HYe.AFj case 5, HnL.invoke case 1,
 * Hnh.invoke case 25, HAS.A01, H26, GOj and AgP.A0k. Static format evidence
 * does not guarantee display in every Web/Android client or permission to publish.
 */
final class BusinessFormBuilder
{
    // SDK authoring bounds, not server capability claims.
    private const MAX_PARAMETERS_BYTES = 262144;
    private const MAX_FIELDS = 100;
    private const MAX_OPTIONS = 100;
    private const NATIVE_CTA = '__localize:FLOWS_COMPLETE_FORM_BUTTON_TITLE';
    private const VISIBILITY = [
        'NAME' => 'full_name_visible',
        'EMAIL' => 'email_visible',
        'PHONE_NUMBER' => 'phone_number_visible',
        'DELIVERY_ADDRESS' => 'delivery_address_visible',
        'CPF_CNPJ' => 'cpf_or_cnpj_visible',
        'CITIZENSHIP_CARD' => 'citizenship_card_visible',
    ];

    /** @param array<string,mixed> $content
     * @return array<string,mixed> */
    public static function build(array $content): array
    {
        if (($content['type'] ?? 'business-form') !== 'business-form') {
            throw new \InvalidArgumentException('Unsupported Business form message type');
        }
        self::validateOptions(array_diff_key($content, array_flip(['type','form','publication'])));
        foreach (['flow_json', 'flow_id', 'well_version', 'flow_message_version', 'flow_action',
            'flow_action_payload', 'creation_source', 'form_type', 'flow_token'] as $key) {
            if (array_key_exists($key, $content)) {
                throw new \InvalidArgumentException('Business form authoring does not accept '.$key.'; provide the published form and its definition');
            }
        }
        $publication = self::object($content['publication'] ?? null, 'publication');
        $flowId = self::text($publication['flowId'] ?? null, 'publication.flowId', 64);
        if (preg_match('/^[1-9][0-9]{0,63}$/D', $flowId) !== 1) throw new \InvalidArgumentException('publication.flowId must be a remote positive numeric ID');
        $wellVersion = self::text($publication['wellVersion'] ?? null, 'publication.wellVersion', 128);
        $form = self::object($content['form'] ?? null, 'form');
        $prepared = self::prepareForm($form);
        $template = $prepared['template'];
        $data = $prepared['data'];
        $isAi = $prepared['isAi'];
        $fields = $prepared['fields'];
        $cta = self::text($content['cta'] ?? self::NATIVE_CTA, 'cta', 1024);
        if ($cta !== self::NATIVE_CTA && preg_match_all('/./us', $cta) > 30) throw new \InvalidArgumentException('Business form CTA cannot exceed 30 Unicode characters');
        $params = [
            'flow_message_version' => '4',
            'flow_id' => $flowId,
            'flow_action_payload' => [
                'screen' => $fields === ['DELIVERY_ADDRESS'] ? 'delivery_address' : 'contact_details',
                'data' => (object)$data,
            ],
            'well_version' => $wellVersion,
            'flow_cta' => $cta,
            'flow_action' => 'navigate',
            // Android Base64.NO_WRAP of the exact template enum name (UTF-8), with padding.
            'flow_token' => base64_encode($template),
            'form_type' => $isAi ? 'ai_form_attachment_tray' : 'template',
        ];
        $json = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAX_PARAMETERS_BYTES) throw new \InvalidArgumentException('Business form parameters exceed the SDK 256 KiB authoring limit');
        $interactive = [
            'body' => ['text' => self::text($content['text'] ?? $form['message_text'] ?? '', 'text', 65536, true)],
            // Native Business capture: message version 3 and an explicit empty message-parameters object.
            'nativeFlowMessage' => ['buttons' => [['name' => 'galaxy_message', 'buttonParamsJson' => $json]], 'messageParamsJson'=>'{}', 'messageVersion' => 3],
        ];
        if (array_key_exists('title', $content)) $interactive['header'] = ['title' => self::text($content['title'], 'title', 4096), 'hasMediaAttachment' => false];
        if (array_key_exists('footer', $content)) $interactive['footer'] = ['text' => self::text($content['footer'], 'footer', 4096)];
        return ['interactiveMessage' => $interactive];
    }

    /** Validate an APK saved-form definition before attempting remote publication.
     * @param array<string,mixed> $form */
    public static function validateForm(array $form): void
    {
        self::prepareForm($form);
    }

    /** Pure message-option validation, before a service creates a remote Flow.
     * @param array<string,mixed> $options */
    public static function validateOptions(array $options): void
    {
        if(array_diff(array_keys($options),['cta','text','title','footer','contextInfo','quote','forward','mentions','pushPriority','ephemeralExpiration','viewOnce','messageId'])!==[])throw new \InvalidArgumentException('Unknown Business form message option');
        foreach(['text'=>65536,'title'=>4096,'footer'=>4096,'messageId'=>256]as$key=>$limit)if(array_key_exists($key,$options))self::text($options[$key],$key,$limit,$key==='text');
        if(array_key_exists('cta',$options)){$cta=self::text($options['cta'],'cta',1024);if($cta!==self::NATIVE_CTA&&preg_match_all('/./us',$cta)>30)throw new \InvalidArgumentException('Business form CTA cannot exceed 30 Unicode characters');}
        if(array_key_exists('viewOnce',$options)&&!is_bool($options['viewOnce']))throw new \InvalidArgumentException('Invalid Business form viewOnce');
        if(array_key_exists('forward',$options)&&!is_bool($options['forward'])&&!is_array($options['forward']))throw new \InvalidArgumentException('Invalid Business form forward');
        if(is_array($options['forward']??null)&&(array_diff(array_keys($options['forward']),['score'])!==[]||(isset($options['forward']['score'])&&(!is_int($options['forward']['score'])||$options['forward']['score']<0||$options['forward']['score']>4294967295))))throw new \InvalidArgumentException('Invalid Business form forwarding score');
        foreach(['contextInfo','quote','mentions']as$key)if(array_key_exists($key,$options)&&!is_array($options[$key]))throw new \InvalidArgumentException('Invalid Business form '.$key);
        if(array_key_exists('pushPriority',$options)&&!in_array($options['pushPriority'],['high','high_force'],true))throw new \InvalidArgumentException('Invalid Business form pushPriority');
        if(array_key_exists('ephemeralExpiration',$options)&&(!is_int($options['ephemeralExpiration'])||$options['ephemeralExpiration']<0||$options['ephemeralExpiration']>4294967295))throw new \InvalidArgumentException('Invalid Business form expiration');
    }

    /** @param array<string,mixed> $form
     * @return array{template:string,data:array<string,mixed>,isAi:bool,fields:list<string>} */
    private static function prepareForm(array $form): array
    {
        foreach ($form as $key => $_) {
            if (!in_array($key, ['id', 'form_title', 'form_description', 'date_time', 'form_type', 'message_text',
                'selected_field_list', 'custom_field_names', 'custom_field_supporting_texts', 'custom_field_input_types',
                'custom_field_options', 'edited_field_keys', 'edited_field_names', 'is_ai_form'], true)) {
                throw new \InvalidArgumentException('Unknown saved Business form field '.$key);
            }
        }
        if(array_key_exists('id',$form)&&(!is_string($form['id'])||!is_int($form['date_time']??null)||(string)$form['date_time']!==$form['id']))throw new \InvalidArgumentException('Saved Business form ID must match its timestamp alias');
        $template = $form['form_type'] ?? null;
        if (!in_array($template, ['CUSTOMER_INFO', 'OFFER_SIGNUP'], true)) {
            throw new \InvalidArgumentException('form.form_type must be CUSTOMER_INFO or OFFER_SIGNUP');
        }
        $title = self::text($form['form_title'] ?? null, 'form.form_title', 4096);
        $description = self::text(array_key_exists('form_description', $form) ? $form['form_description'] : '', 'form.form_description', 16384, true);
        if (isset($form['date_time']) && (!is_int($form['date_time']) || $form['date_time'] < 0)) {
            throw new \InvalidArgumentException('form.date_time must be a non-negative integer timestamp or null');
        }
        $isAi = array_key_exists('is_ai_form', $form) ? $form['is_ai_form'] : false;
        if (!is_bool($isAi)) throw new \InvalidArgumentException('form.is_ai_form must be boolean');
        $fields = self::list($form['selected_field_list'] ?? null, 'form.selected_field_list', self::MAX_FIELDS);
        if ($fields === []) throw new \InvalidArgumentException('A Business form must contain at least one selected field');
        $customCount = 0;
        $seen = [];
        foreach ($fields as $field) {
            if ($field === 'CUSTOM') { ++$customCount; continue; }
            if (!is_string($field) || !array_key_exists($field, self::VISIBILITY)) {
                throw new \InvalidArgumentException('Unsupported selected Business form field');
            }
            if (isset($seen[$field])) throw new \InvalidArgumentException('Selected predefined Business form fields must be unique');
            $seen[$field] = true;
        }
        $custom = self::customFields($form, $customCount);
        $editedKeys = self::list($form['edited_field_keys'] ?? [], 'form.edited_field_keys', self::MAX_FIELDS);
        $editedNames = self::list($form['edited_field_names'] ?? [], 'form.edited_field_names', self::MAX_FIELDS);
        if (count($editedKeys) !== count($editedNames)) {
            throw new \InvalidArgumentException('Edited Business form field keys and labels must have matching counts');
        }
        $edited = [];
        foreach ($editedKeys as $index => $field) {
            if (!is_string($field) || !isset($seen[$field]) || isset($edited[$field])) {
                throw new \InvalidArgumentException('Edited Business form fields must reference unique selected predefined fields');
            }
            $edited[$field] = self::text($editedNames[$index], 'form.edited_field_names', 1024);
        }
        $data = array_fill_keys(array_values(self::VISIBILITY), false);
        $customFields = [];
        $customIndex = 0;
        foreach ($fields as $field) {
            if ($field === 'CUSTOM') {
                $customFields[] = $custom[$customIndex++];
            } elseif (isset($edited[$field]) || $field === 'DELIVERY_ADDRESS') {
                // The APK sends renamed predefined fields and delivery address as custom text inputs.
                $customFields[] = ['type' => 'TEXT_INPUT', 'label' => $edited[$field] ?? 'Endereço de entrega'];
            } else {
                $data[self::VISIBILITY[$field]] = true;
            }
        }
        if ($customFields !== []) $data['custom_fields'] = $customFields;
        if ($template === 'OFFER_SIGNUP') {
            $data['offer_name'] = $title;
            $data['offer_description'] = $description;
        }
        self::text(array_key_exists('message_text', $form) ? $form['message_text'] : '', 'form.message_text', 65536, true);
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_PARAMETERS_BYTES - 4096) {
            throw new \InvalidArgumentException('Business form definition exceeds the SDK authoring limit');
        }
        return ['template' => $template, 'data' => $data, 'isAi' => $isAi, 'fields' => $fields];
    }

    /** @param array<string,mixed> $form
     * @return list<array<string,mixed>> */
    private static function customFields(array $form, int $count): array
    {
        $lists = [];
        foreach (['names', 'supporting_texts', 'input_types', 'options'] as $suffix) {
            $name = 'custom_field_'.$suffix;
            $lists[$suffix] = self::list($form[$name] ?? [], 'form.'.$name, self::MAX_FIELDS);
            if (count($lists[$suffix]) !== $count) throw new \InvalidArgumentException('Custom Business form parallel arrays must match the CUSTOM field count');
        }
        $out = [];
        for ($index = 0; $index < $count; ++$index) {
            $type = $lists['input_types'][$index];
            if (!in_array($type, ['TEXT_INPUT', 'TEXT_AREA', 'DROPDOWN'], true)) {
                throw new \InvalidArgumentException('Custom Business fields support TEXT_INPUT, TEXT_AREA and DROPDOWN');
            }
            $field = ['type' => $type, 'label' => self::text($lists['names'][$index], 'custom field label', 1024)];
            $helper = self::text($lists['supporting_texts'][$index], 'custom field supporting text', 4096, true);
            if ($helper !== '') $field['helper_text'] = $helper;
            $options = self::list($lists['options'][$index], 'custom field options', self::MAX_OPTIONS);
            if ($type === 'DROPDOWN' && $options === []) throw new \InvalidArgumentException('A Business form dropdown requires options');
            if ($type !== 'DROPDOWN' && $options !== []) throw new \InvalidArgumentException('Only a Business form dropdown accepts options');
            if ($options !== []) {
                $values = [];
                foreach ($options as $option) {
                    $option = self::text($option, 'custom field option', 1024);
                    if (in_array($option, $values, true)) throw new \InvalidArgumentException('Business form dropdown options must be unique');
                    $values[] = $option;
                }
                $field['options'] = $values;
            }
            $out[] = $field;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) throw new \InvalidArgumentException($field.' must be an object');
        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $field, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            throw new \InvalidArgumentException($field.' must be a list with at most '.$limit.' items');
        }
        return $value;
    }

    private static function text(mixed $value, string $field, int $limit, bool $empty = false): string
    {
        if (!is_string($value) || (!$empty && trim($value) === '') || strlen($value) > $limit || preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException($field.' must contain valid '.($empty ? '' : 'non-empty ').'UTF-8 text within '.$limit.' bytes');
        }
        return $value;
    }
}
