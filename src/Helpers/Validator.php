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
            $value = $data[$field] ?? null;

            foreach ($ruleSet as $r) {
                $ruleName = $r;
                $parameter = null;

                if (strpos($r, ':') !== false) {
                    [$ruleName, $parameter] = explode(':', $r, 2);
                }

                $method = 'validate' . ucfirst($ruleName);

                if (method_exists($this, $method)) {
                    $this->$method($field, $value, $parameter);
                    if ($ruleName === 'required' && isset($this->errors[$field])) {
                        break;
                    }
                }
            }
        }
    }

    protected function validateRequired($field, $value, $param = null) {

        // FILE UPLOAD
        if (is_array($value) && isset($value['error'])) {
            if ($value['error'] === UPLOAD_ERR_NO_FILE) {
                $this->errors[$field][] =
                    $this->getMessage($field, 'required', "$field is required.");
            }
            return;
        }

        // STRING / NUMBER
        if ($value === null || $value === '') {
            $this->errors[$field][] =
                $this->getMessage($field, 'required', "$field is required.");
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

    protected function validateFile($field, $file, $params = null)
    {
        $allowedTypes = [];
        $allowedExts = [];
        $maxSize = null;

        if (is_string($params)) {
            $rules = explode('|', $params);
            foreach ($rules as $rule) {
                if (str_contains($rule, '/')) {
                    $mimes = explode(',', $rule);
                    foreach ($mimes as $mime) {
                        $allowedTypes[] = trim($mime);
                        $allowedExts[] = explode('/', trim($mime))[1] ?? trim($mime);
                    }
                } elseif (str_starts_with($rule, 'ext:')) {
                    $allowedExts = array_map('trim', explode(',', str_replace('ext:', '', $rule)));
                } elseif (str_starts_with($rule, 'max:')) {
                    $maxSize = (int) str_replace('max:', '', $rule); // ukuran dalam KB
                }
            }
        }

        if (!isset($file['tmp_name']) || $file['error'] === 4) {
            $this->errors[$field][] = $this->getMessage($field, 'required', "$field is required.");
            return;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $this->errors[$field][] = $this->getMessage($field, 'file', "$field upload is invalid.");
            return;
        }

        if ($maxSize !== null && $file['size'] > $maxSize * 1024) {
            $this->errors[$field][] = $this->getMessage($field, 'filesize', "$field must not exceed {$maxSize}KB.");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!empty($allowedTypes) && !in_array($mimeType, $allowedTypes)) {
            $this->errors[$field][] = $this->getMessage($field, 'filetype', "$field must be one of: " . implode(', ', $allowedTypes));
        }

        if (!empty($allowedExts) && !in_array($ext, $allowedExts)) {
            $this->errors[$field][] = $this->getMessage($field, 'extension', "$field file extension must be one of: " . implode(', ', $allowedExts));
        }
    }

    protected function validateImage($field, $file, $params = null)
    {
        $allowedTypes = [];
        $allowedExts = [];
        $maxSize = null;
        $minWidth = null;
        $minHeight = null;

        if (is_string($params)) {
            $rules = explode('|', $params);
            foreach ($rules as $rule) {
                if (str_contains($rule, 'image/')) {
                    $mimes = explode(',', $rule);
                    foreach ($mimes as $mime) {
                        $allowedTypes[] = trim($mime);
                        $allowedExts[] = explode('/', trim($mime))[1];
                    }
                } elseif (str_starts_with($rule, 'max:')) {
                    $maxSize = (int) str_replace('max:', '', $rule); // ukuran dalam KB
                } elseif (str_starts_with($rule, 'minWidth:')) {
                    $minWidth = (int) str_replace('minWidth:', '', $rule);
                } elseif (str_starts_with($rule, 'minHeight:')) {
                    $minHeight = (int) str_replace('minHeight:', '', $rule);
                }
            }
        }

        if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $this->errors[$field][] = $this->getMessage($field, 'file', "$field upload is invalid.");
            return;
        }

        if ($maxSize !== null && $file['size'] > $maxSize * 1024) {
            $this->errors[$field][] = $this->getMessage(
                $field,
                'filesize',
                "$field must not exceed {$maxSize}KB."
            );
        }

        if (!empty($allowedTypes)) {
            $this->validateFileType($field, $file, $allowedTypes, $allowedExts);
        }

        if ($minWidth !== null || $minHeight !== null) {
            $imageInfo = @getimagesize($file['tmp_name']);
            if ($imageInfo) {
                [$width, $height] = $imageInfo;
                if ($minWidth && $width < $minWidth) {
                    $this->errors[$field][] = "$field width must be at least {$minWidth}px.";
                }
                if ($minHeight && $height < $minHeight) {
                    $this->errors[$field][] = "$field height must be at least {$minHeight}px.";
                }
            } else {
                $this->errors[$field][] = "$field must be a valid image file.";
            }
        }
    }

    protected function validateFileType($field, $file, $allowedTypes, $allowedExts)
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($mimeType, $allowedTypes)) {
            $this->errors[$field][] = $this->getMessage(
                $field,
                'filetype',
                "$field must be one of the following MIME types: " . implode(', ', $allowedTypes) . "."
            );
        }

        if (!in_array($ext, $allowedExts)) {
            $this->errors[$field][] = $this->getMessage(
                $field,
                'extension',
                "$field file extension must be one of: " . implode(', ', $allowedExts) . "."
            );
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
