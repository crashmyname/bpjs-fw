<?php

namespace Bpjs\Framework\Core;

use Bpjs\Framework\Core\Request;
use Bpjs\Framework\Helpers\Validator;
use Exception;

abstract class FormRequest
{
    protected Request $request;
    protected array $validatedData = [];
    protected array $errors = [];

    public function __construct(Request $request)
    {
        $this->request = $request;

        if (!$this->authorize()) {
            throw new Exception("Unauthorized", 403);
        }

        $this->validate();
    }

    abstract public function rules(): array;

    public function messages(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function validate()
    {
        $data = $this->request->all();

        $errors = Validator::make(
            $data,
            $this->rules(),
            $this->messages()
        );

        if (!empty($errors)) {
            $this->errors = $errors;

            if ($this->request->expectsJson()) {
                http_response_code(422);
                echo json_encode([
                    'message' => 'Validation failed',
                    'errors' => $errors
                ]);
                exit;
            }

            throw new Exception("Validation failed");
        }

        $this->validatedData = array_intersect_key($data, $this->rules());
    }

    public function validated(): array
    {
        return $this->validatedData;
    }

    public function input($key, $default = null)
    {
        return $this->validatedData[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->validatedData;
    }
}