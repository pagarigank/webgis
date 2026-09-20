<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\GIS\Domain\AttributeValidator;

class AttributeValidatorTest extends TestCase
{
    private AttributeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AttributeValidator();
    }

    public function testValidatesRequiredField(): void
    {
        $fields = [
            ['field_name' => 'title', 'field_type' => 'STRING', 'required' => true]
        ];

        $errors = $this->validator->validate($fields, []);
        $this->assertArrayHasKey('title', $errors);
        $this->assertEquals('Field is required.', $errors['title']);

        $errors = $this->validator->validate($fields, ['title' => '']);
        $this->assertArrayHasKey('title', $errors);

        $errors = $this->validator->validate($fields, ['title' => 'Hello']);
        $this->assertEmpty($errors);
    }

    public function testValidatesInteger(): void
    {
        $fields = [
            ['field_name' => 'count', 'field_type' => 'INTEGER']
        ];

        $errors = $this->validator->validate($fields, ['count' => 'abc']);
        $this->assertArrayHasKey('count', $errors);
        $this->assertEquals('Must be an integer.', $errors['count']);

        $errors = $this->validator->validate($fields, ['count' => 123]);
        $this->assertEmpty($errors);

        $errors = $this->validator->validate($fields, ['count' => '123']);
        $this->assertEmpty($errors);
    }

    public function testValidatesMinMaxNumeric(): void
    {
        $fields = [
            [
                'field_name' => 'price', 
                'field_type' => 'DECIMAL',
                'validation_rules' => json_encode(['min' => 10, 'max' => 100])
            ]
        ];

        $errors = $this->validator->validate($fields, ['price' => 5]);
        $this->assertArrayHasKey('price', $errors);
        $this->assertStringContainsString('at least 10', $errors['price']);

        $errors = $this->validator->validate($fields, ['price' => 150]);
        $this->assertArrayHasKey('price', $errors);
        $this->assertStringContainsString('at most 100', $errors['price']);

        $errors = $this->validator->validate($fields, ['price' => 50]);
        $this->assertEmpty($errors);
    }

    public function testValidatesMinMaxStringLength(): void
    {
        $fields = [
            [
                'field_name' => 'code', 
                'field_type' => 'STRING',
                'validation_rules' => json_encode(['min' => 3, 'max' => 5])
            ]
        ];

        $errors = $this->validator->validate($fields, ['code' => 'ab']);
        $this->assertArrayHasKey('code', $errors);
        $this->assertStringContainsString('at least 3', $errors['code']);

        $errors = $this->validator->validate($fields, ['code' => 'abcdef']);
        $this->assertArrayHasKey('code', $errors);
        $this->assertStringContainsString('at most 5', $errors['code']);

        $errors = $this->validator->validate($fields, ['code' => 'abc']);
        $this->assertEmpty($errors);
    }

    public function testValidatesRegex(): void
    {
        $fields = [
            [
                'field_name' => 'pin', 
                'field_type' => 'STRING',
                'validation_rules' => json_encode(['regex' => '^[0-9]{4}$', 'regex_message' => 'Must be 4 digits.'])
            ]
        ];

        $errors = $this->validator->validate($fields, ['pin' => '123']);
        $this->assertArrayHasKey('pin', $errors);
        $this->assertEquals('Must be 4 digits.', $errors['pin']);

        $errors = $this->validator->validate($fields, ['pin' => '1234']);
        $this->assertEmpty($errors);
    }

    public function testValidatesOptionsEnum(): void
    {
        $fields = [
            [
                'field_name' => 'status', 
                'field_type' => 'ENUM',
                'options' => json_encode(['ACTIVE', 'INACTIVE'])
            ]
        ];

        $errors = $this->validator->validate($fields, ['status' => 'PENDING']);
        $this->assertArrayHasKey('status', $errors);
        $this->assertEquals('Value must be one of the allowed options.', $errors['status']);

        $errors = $this->validator->validate($fields, ['status' => 'ACTIVE']);
        $this->assertEmpty($errors);
    }

    public function testValidatesDate(): void
    {
        $fields = [
            ['field_name' => 'event_date', 'field_type' => 'DATE']
        ];

        $errors = $this->validator->validate($fields, ['event_date' => '01/01/2023']);
        $this->assertArrayHasKey('event_date', $errors);

        $errors = $this->validator->validate($fields, ['event_date' => '2023-01-01']);
        $this->assertEmpty($errors);
    }

    public function testValidatesUuidTypes(): void
    {
        $fields = [
            ['field_name' => 'owner_id', 'field_type' => 'USER']
        ];

        $errors = $this->validator->validate($fields, ['owner_id' => 'not-a-uuid']);
        $this->assertArrayHasKey('owner_id', $errors);

        $errors = $this->validator->validate($fields, ['owner_id' => '550e8400-e29b-41d4-a716-446655440000']);
        $this->assertEmpty($errors);
    }

    public function testValidatesJsonType(): void
    {
        $fields = [
            ['field_name' => 'metadata', 'field_type' => 'JSON']
        ];

        // Valid array
        $errors = $this->validator->validate($fields, ['metadata' => ['key' => 'value']]);
        $this->assertEmpty($errors);

        // Valid JSON string
        $errors = $this->validator->validate($fields, ['metadata' => '{"key":"value"}']);
        $this->assertEmpty($errors);

        // Invalid JSON string
        $errors = $this->validator->validate($fields, ['metadata' => '{invalid}']);
        $this->assertArrayHasKey('metadata', $errors);
    }
}
