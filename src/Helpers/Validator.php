<?php
namespace Bpjs\Framework\Helpers;

use Bpjs\Framework\Helpers\Database;
use DateTime as Date;

class Validator {
    protected $errors = [];
    protected $messages = [];

    public static function make($data, $rules, $messages = []) {
        $validator = new self();
        $validator->messages = $messages;
        $validator->validate($data, $rules);
        return $validator->errors;
    }

    private function getMessage($field, $rule, $default, $replace = []) {
        $key = "$field.$rule";
        $msg = $this->messages[$key] ?? $default;
        foreach ($replace as $search => $value) {
            $msg = str_replace(':' . $search, $value, $msg);
        }
        return $msg;
    }

    public function validate($data, $rules) {
        foreach ($rules as $field => $rule) {
            $ruleSet = explode('|', $rule);
            foreach ($ruleSet as $r) {
                $ruleName = $r;
                $parameter = null;

                if (strpos($r, ':') !== false) {
                    list($ruleName, $parameter) = explode(':', $r);
                }

                $method = 'validate' . ucfirst($ruleName);

                if (method_exists($this, $method)) {
                    $this->$method($field, $data[$field] ?? null, $parameter);
                }
            }
        }
    }

    protected function validateRequired($field, $value, $param = null) {
        if (is_null($value) || $value === '') {
            $this->errors[$field][] = $this->getMessage($field, 'required', "$field is required.");
        }
    }

    protected function validateMin($field, $value, $min) {
        if (strlen($value) < $min) {
            $this->errors[$field][] = $this->getMessage($field, 'min', "$field must be at least $min characters.", ['min' => $min]);
        }
    }

    protected function validateMax($field, $value, $max) {
        if (strlen($value) > $max) {
            $this->errors[$field][] = $this->getMessage($field, 'max', "$field must be no more than $max characters.", ['max' => $max]);
        }
    }

    protected function validateNumeric($field, $value, $param = null) {
        if (!is_numeric($value)) {
            $this->errors[$field][] = $this->getMessage($field, 'numeric', "$field must be a number.");
        }
    }

    protected function validateEmail($field, $value, $param = null) {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = $this->getMessage($field, 'email', "$field must be a valid email address.");
        }
    }

    protected function validateConfirmed($field, $value, $confirmationField) {
        if ($value !== ($_POST[$confirmationField] ?? null)) {
            $this->errors[$field][] = $this->getMessage($field, 'confirmed', "$field must match $confirmationField.");
        }
    }

    protected function validateAge($field, $value, $minAge) {
        $currentYear = date('Y');
        $birthYear = date('Y', strtotime($value));
        $age = $currentYear - $birthYear;

        if ($age < $minAge) {
            $this->errors[$field][] = $this->getMessage($field, 'age', "$field must be at least $minAge years old.", ['min' => $minAge]);
        }
    }

    protected function validateRegex($field, $value, $pattern) {
        if (!preg_match($pattern, $value)) {
            $this->errors[$field][] = $this->getMessage($field, 'regex', "$field does not match the required format.");
        }
    }

    protected function validateFileSize($field, $file, $maxSize) {
        if ($file['size'] > $maxSize) {
            $this->errors[$field][] = $this->getMessage($field, 'filesize', "$field must not exceed " . ($maxSize / 1024) . " KB.", ['size' => $maxSize]);
        }
    }

    protected function validateDate($field, $value, $format = 'Y-m-d') {
        $d = Date::createFromFormat($format, $value);
        if (!$d || $d->format($format) !== $value) {
            $this->errors[$field][] = $this->getMessage($field, 'date', "$field must be a valid date in the format $format.", ['format' => $format]);
        }
    }

    protected function validateAlphanumeric($field, $value, $param = null) {
        if (!ctype_alnum($value)) {
            $this->errors[$field][] = $this->getMessage($field, 'alphanumeric', "$field must be alphanumeric.");
        }
    }

    protected function validateImage($field, $file, $params = null) {
        $allowedTypes = [];
        $maxSize = null;
        $minWidth = null;
        $minHeight = null;

        if (is_string($params)) {
            $parts = explode(',', $params);
            $allowedTypes = array_map('trim', $parts);
        }

        if (!isset($file['tmp_name']) || $file['tmp_name'] === '' || $file['error'] === 4) {
            $this->errors[$field][] = $this->getMessage(
                $field,
                'required',
                "$field is required."
            );
            return;
        }

        if ($maxSize !== null) {
            $this->validateFileSize($field, $file, $maxSize);
        }

        if (!empty($allowedTypes)) {
            $this->validateFileType($field, $file, $allowedTypes);
        }

        if ($minWidth !== null || $minHeight !== null) {
            $imageInfo = getimagesize($file['tmp_name']);
            if ($imageInfo) {
                list($width, $height) = $imageInfo;
                if ($minWidth !== null && $width < $minWidth) {
                    $this->errors[$field][] = $this->getMessage($field, 'image', "$field must be at least $minWidth pixels wide.", ['minWidth' => $minWidth]);
                }
                if ($minHeight !== null && $height < $minHeight) {
                    $this->errors[$field][] = $this->getMessage($field, 'image', "$field must be at least $minHeight pixels tall.", ['minHeight' => $minHeight]);
                }
            } else {
                $this->errors[$field][] = $this->getMessage($field, 'image', "$field must be a valid image.");
            }
        }
    }

    protected function validateFileType($field, $file, $allowedTypes) {
        $fileType = mime_content_type($file['tmp_name']);
        if (!in_array($fileType, $allowedTypes)) {
            $this->errors[$field][] = $this->getMessage($field, 'filetype', "$field must be one of the following types: " . implode(', ', $allowedTypes) . ".", ['types' => implode(', ', $allowedTypes)]);
        }
    }

    protected function validateUnique($field, $value, $table) {
        if ($this->isValueExists($table, $field, $value)) {
            $this->errors[$field][] = $this->getMessage($field, 'unique', "$field must be unique.");
        }
    }

    private $connection;

    public function __construct() {
        $this->connect();
    }

    private function connect() {
        $this->connection = Database::connection();
        if ($this->connection === null) {
            die('Connection Failed');
        }
    }

    private function isValueExists($table, $field, $value) {
        $stmt = $this->connection->prepare("SELECT COUNT(*) FROM $table WHERE $field = ?");
        $stmt->execute([$value]);
        return $stmt->fetchColumn() > 0;
    }

    protected function validatePassword($field, $value, $param = null) {
        $minLength = 8;
        $hasUppercase = preg_match('/[A-Z]/', $value);
        $hasLowercase = preg_match('/[a-z]/', $value);
        $hasNumber = preg_match('/\d/', $value);
        $hasSpecialChar = preg_match('/[^a-zA-Z\d]/', $value);

        if (strlen($value) < $minLength || !$hasUppercase || !$hasLowercase || !$hasNumber || !$hasSpecialChar) {
            $this->errors[$field][] = $this->getMessage(
                $field,
                'password',
                "$field must be at least $minLength characters long and contain uppercase letters, lowercase letters, numbers, and special characters.",
                ['min' => $minLength]
            );
        }
    }

    protected function validateInArray($field, $value, $allowedValues) {
        $arr = explode(',', $allowedValues);
        if (!in_array($value, $arr)) {
            $this->errors[$field][] = $this->getMessage($field, 'in', "$field must be one of the following: " . implode(', ', $arr) . ".", ['values' => implode(', ', $arr)]);
        }
    }
}
