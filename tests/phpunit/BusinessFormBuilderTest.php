<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\BusinessFormBuilder;
use MVO\Protocol\SchemaCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessFormBuilderTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function content(): array
    {
        return [
            'type' => 'business-form',
            'publication' => ['flowId' => '12345678901234567890', 'wellVersion' => '1.0'],
            'form' => [
                'form_title' => 'Dados do cliente', 'form_description' => '', 'date_time' => 1792671445000,
                'form_type' => 'CUSTOMER_INFO', 'message_text' => 'Preencha seus dados para continuar.',
                'selected_field_list' => ['NAME', 'EMAIL'],
            ],
        ];
    }

    /** @param array<string,mixed> $message
     * @return array<string,mixed> */
    private static function parameters(array $message): array
    {
        return json_decode($message['interactiveMessage']['nativeFlowMessage']['buttons'][0]['buttonParamsJson'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAndroidBusinessVersionFourUsesRealPublicationAndEnumToken(): void
    {
        $message = BusinessFormBuilder::build(self::content());
        $params = self::parameters($message);
        self::assertSame('galaxy_message', $message['interactiveMessage']['nativeFlowMessage']['buttons'][0]['name']);
        self::assertSame(1, $message['interactiveMessage']['nativeFlowMessage']['messageVersion']);
        self::assertSame('4', $params['flow_message_version']);
        self::assertSame('12345678901234567890', $params['flow_id']);
        self::assertSame('1.0', $params['well_version']);
        self::assertSame('Q1VTVE9NRVJfSU5GTw==', $params['flow_token']);
        self::assertSame('template', $params['form_type']);
        self::assertSame('navigate', $params['flow_action']);
        self::assertSame('__localize:FLOWS_COMPLETE_FORM_BUTTON_TITLE', $params['flow_cta']);
        self::assertSame('contact_details', $params['flow_action_payload']['screen']);
        self::assertTrue($params['flow_action_payload']['data']['full_name_visible']);
        self::assertTrue($params['flow_action_payload']['data']['email_visible']);
        self::assertFalse($params['flow_action_payload']['data']['phone_number_visible']);
        self::assertFalse($params['flow_action_payload']['data']['delivery_address_visible']);
        self::assertFalse($params['flow_action_payload']['data']['cpf_or_cnpj_visible']);
        self::assertFalse($params['flow_action_payload']['data']['citizenship_card_visible']);
        self::assertArrayNotHasKey('creation_source', $params);
        self::assertArrayNotHasKey('flow_json', $params);
        self::assertArrayNotHasKey('flow_metadata', $params);
        self::assertSame($message, SchemaCodec::decode('Message', SchemaCodec::encode('Message', $message)));
    }

    public function testAllThreeCustomWireTypesAndHelpersRetainSelectedOrder(): void
    {
        $content = self::content();
        $content['form']['selected_field_list'] = ['CUSTOM', 'NAME', 'CUSTOM', 'CUSTOM'];
        $content['form']['custom_field_names'] = ['Apelido', 'Observação', 'Departamento'];
        $content['form']['custom_field_supporting_texts'] = ['', 'Informação adicional', ''];
        $content['form']['custom_field_input_types'] = ['TEXT_INPUT', 'TEXT_AREA', 'DROPDOWN'];
        $content['form']['custom_field_options'] = [[], [], ['Suporte', 'Comercial']];
        $data = self::parameters(BusinessFormBuilder::build($content))['flow_action_payload']['data'];
        self::assertTrue($data['full_name_visible']);
        self::assertSame([
            ['type' => 'TEXT_INPUT', 'label' => 'Apelido'],
            ['type' => 'TEXT_AREA', 'label' => 'Observação', 'helper_text' => 'Informação adicional'],
            ['type' => 'DROPDOWN', 'label' => 'Departamento', 'options' => ['Suporte', 'Comercial']],
        ], $data['custom_fields']);
    }

    public function testRenamedPredefinedFieldIsCustomAndItsStandardVisibilityIsFalse(): void
    {
        $content = self::content();
        $content['form']['edited_field_keys'] = ['NAME'];
        $content['form']['edited_field_names'] = ['Nome da pessoa responsável'];
        $data = self::parameters(BusinessFormBuilder::build($content))['flow_action_payload']['data'];
        self::assertFalse($data['full_name_visible']);
        self::assertTrue($data['email_visible']);
        self::assertSame([['type' => 'TEXT_INPUT', 'label' => 'Nome da pessoa responsável']], $data['custom_fields']);
    }

    public function testDeliveryAddressOnlyUsesItsEntryScreenAndCustomField(): void
    {
        $content = self::content();
        $content['form']['selected_field_list'] = ['DELIVERY_ADDRESS'];
        $params = self::parameters(BusinessFormBuilder::build($content));
        self::assertSame('delivery_address', $params['flow_action_payload']['screen']);
        self::assertFalse($params['flow_action_payload']['data']['delivery_address_visible']);
        self::assertSame([['type' => 'TEXT_INPUT', 'label' => 'Endereço de entrega']], $params['flow_action_payload']['data']['custom_fields']);
        $content['form']['selected_field_list'][] = 'NAME';
        self::assertSame('contact_details', self::parameters(BusinessFormBuilder::build($content))['flow_action_payload']['screen']);
    }

    public function testOfferSignupAndAiFormHaveTheirActualDiscriminators(): void
    {
        $content = self::content();
        $content['form']['form_type'] = 'OFFER_SIGNUP';
        $content['form']['form_title'] = 'Quero receber novidades';
        $content['form']['form_description'] = 'Inscreva-se para receber nossas ofertas.';
        $content['form']['is_ai_form'] = true;
        $params = self::parameters(BusinessFormBuilder::build($content));
        self::assertSame('T0ZGRVJfU0lHTlVQ', $params['flow_token']);
        self::assertSame('ai_form_attachment_tray', $params['form_type']);
        self::assertSame('Quero receber novidades', $params['flow_action_payload']['data']['offer_name']);
        self::assertSame('Inscreva-se para receber nossas ofertas.', $params['flow_action_payload']['data']['offer_description']);
    }

    public function testPresentationOptionsDoNotChangeTheRemoteFormIdentity(): void
    {
        $content = self::content() + ['cta' => 'Responder agora', 'text' => 'Convite personalizado', 'title' => 'Atendimento', 'footer' => 'MVO'];
        $message = BusinessFormBuilder::build($content);
        self::assertSame(['text' => 'Convite personalizado'], $message['interactiveMessage']['body']);
        self::assertSame(['title' => 'Atendimento', 'hasMediaAttachment' => false], $message['interactiveMessage']['header']);
        self::assertSame(['text' => 'MVO'], $message['interactiveMessage']['footer']);
        self::assertSame('Responder agora', self::parameters($message)['flow_cta']);
        self::assertSame('12345678901234567890', self::parameters($message)['flow_id']);
    }

    public function testPureValidationDoesNotRequireAPublicationOrAlterTheDefinition(): void
    {
        $form = self::content()['form'];
        $original = $form;
        BusinessFormBuilder::validateForm($form);
        self::assertSame($original, $form);
        $form['message_text'] = "\xff";
        $this->expectException(\InvalidArgumentException::class);
        BusinessFormBuilder::validateForm($form);
    }

    public function testOuterMessageOptionsAreLeftForTheNormalMessagePipeline(): void
    {
        $content = self::content() + ['messageId' => 'FIXTURE', 'contextInfo' => ['isForwarded' => true], 'quote' => ['id' => 'QUOTE'], 'mentions' => ['123@lid']];
        $params = self::parameters(BusinessFormBuilder::build($content));
        foreach (['messageId', 'contextInfo', 'quote', 'mentions'] as $key) self::assertArrayNotHasKey($key, $params);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidContent(): iterable
    {
        foreach ([
            ['publication', null], ['publication', []], ['publication', ['flowId' => 123, 'wellVersion' => '1']],
            ['publication', ['flowId' => 'https://example.invalid/flow', 'wellVersion' => '1']],
            ['publication', ['flowId' => '0', 'wellVersion' => '1']],
            ['publication', ['flowId' => '00123', 'wellVersion' => '1']],
            ['publication', ['flowId' => '123', 'wellVersion' => 1]], ['publication', ['flowId' => str_repeat('1', 65), 'wellVersion' => '1']],
            ['form', null], ['type', 'form'], ['flow_json', ['screens' => []]], ['creation_source', 'FLEXIBLE_CHECKOUT'],
            ['flow_id', '123'], ['flow_token', 'invented-token'], ['cta', str_repeat('á', 31)], ['title', "\xff"],
            ['text', str_repeat('x', 65537)],
        ] as $index => [$key, $value]) {
            $content = self::content(); $content[$key] = $value;
            yield 'outer '.$index => [$content];
        }
        foreach ([
            ['form_type', 'UNKNOWN'], ['form_title', ''], ['selected_field_list', []],
            ['selected_field_list', ['NAME', 'NAME']], ['selected_field_list', ['NAME', 'UNKNOWN']],
            ['selected_field_list', ['NAME' => true]], ['selected_field_list', array_fill(0, 101, 'CUSTOM')],
            ['custom_field_names', ['Unexpected custom field']], ['is_ai_form', 'true'], ['is_ai_form', null], ['date_time', 1.5],
            ['date_time', -1], ['form_description', "\xc3"], ['edited_field_keys', ['NAME']],
            ['edited_field_names', ['Unexpected rename']], ['flow_json', ['screens' => []]],
        ] as $index => [$key, $value]) {
            $content = self::content(); $content['form'][$key] = $value;
            yield 'form '.$index => [$content];
        }
        foreach (['missing options', 'duplicate options', 'unsupported type', 'text options', 'missing parallel array'] as $case) {
            $content = self::content();
            $content['form'] += ['custom_field_names' => ['Campo'], 'custom_field_supporting_texts' => [''], 'custom_field_input_types' => ['DROPDOWN'], 'custom_field_options' => [['Sim', 'Não']]];
            $content['form']['selected_field_list'] = ['CUSTOM'];
            if ($case === 'missing options') $content['form']['custom_field_options'] = [[]];
            if ($case === 'duplicate options') $content['form']['custom_field_options'] = [['Sim', 'Sim']];
            if ($case === 'unsupported type') $content['form']['custom_field_input_types'] = ['DATE_PICKER'];
            if ($case === 'text options') $content['form']['custom_field_input_types'] = ['TEXT_INPUT'];
            if ($case === 'missing parallel array') unset($content['form']['custom_field_supporting_texts']);
            yield $case => [$content];
        }
        $content = self::content();
        $content['form']['edited_field_keys'] = ['NAME', 'NAME'];
        $content['form']['edited_field_names'] = ['Primeiro', 'Segundo'];
        yield 'duplicate rename IDs' => [$content];
        $content['form']['edited_field_keys'] = ['NAME', 'PHONE_NUMBER'];
        yield 'rename unselected field' => [$content];
    }

    #[DataProvider('invalidContent')]
    public function testInvalidInputCannotCreateAnUnregisteredOrAmbiguousForm(array $content): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BusinessFormBuilder::build($content);
    }
}
