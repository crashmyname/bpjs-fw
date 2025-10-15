<?php
namespace Bpjs\Framework\Helpers;

class RateLimiter
{
    private $limit = 500;
    private $timeFrame = 3600;
    private $requests = [];

    public function __construct() {
        if (!isset($_SESSION['requests'])) {
            $_SESSION['requests'] = [];
        }
        $this->requests = &$_SESSION['requests'];
    }

    public function check($ipAddress) {
        $now = time();
        if (!isset($this->requests[$ipAddress])) {
            $this->requests[$ipAddress] = [];
        }

        $this->requests[$ipAddress] = array_filter($this->requests[$ipAddress], function($timestamp) use ($now) {
            return ($now - $timestamp) < $this->timeFrame;
        });

        if (count($this->requests[$ipAddress]) >= $this->limit) {
            return false;
        }

        $this->requests[$ipAddress][] = $now;
        return true;
    }
}