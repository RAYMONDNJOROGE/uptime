<?php
/**
 * MikroTik RouterOS API Client
 * Handles communication with MikroTik router
 */

class MikrotikAPI {
    private $socket;
    private $connected = false;
    private $host;
    private $user;
    private $pass;
    private $port;
    
    public function __construct($host, $user, $pass, $port = 8728) {
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->port = $port;
    }
    
    public function connect() {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 3);
        
        if (!$this->socket) {
            logMessage("MikroTik connection failed: $errstr ($errno)", 'ERROR');
            return false;
        }
        
        $this->connected = true;
        
        // Login
        $this->write('/login', false);
        $response = $this->read(false);
        
        if (isset($response[0]['!trap'])) {
            logMessage("MikroTik login failed", 'ERROR');
            return false;
        }
        
        $this->write('/login', false, ['=name=' . $this->user, '=password=' . $this->pass]);
        $response = $this->read(false);
        
        if (isset($response[0]['!trap'])) {
            logMessage("MikroTik authentication failed", 'ERROR');
            return false;
        }
        
        logMessage("MikroTik connected successfully", 'INFO');
        return true;
    }
    
    public function disconnect() {
        if ($this->connected) {
            fclose($this->socket);
            $this->connected = false;
        }
    }
    
    private function write($command, $param2 = true, $param3 = []) {
        if (!$this->connected) return false;
        
        $params = is_array($param2) ? $param2 : $param3;
        
        fwrite($this->socket, $this->encodeLength(strlen($command)) . $command);
        
        foreach ($params as $param) {
            fwrite($this->socket, $this->encodeLength(strlen($param)) . $param);
        }
        
        fwrite($this->socket, chr(0));
    }
    
    private function read($parse = true) {
        if (!$this->connected) return [];
        
        $response = [];
        $current = [];
        
        while (true) {
            $length = $this->decodeLength();
            
            if ($length === 0) {
                if (!empty($current)) {
                    $response[] = $current;
                }
                break;
            }
            
            $line = fread($this->socket, $length);
            
            if (substr($line, 0, 1) === '!') {
                if (!empty($current)) {
                    $response[] = $current;
                }
                $current = [$line];
            } else {
                $current[] = $line;
            }
        }
        
        if ($parse) {
            return $this->parseResponse($response);
        }
        
        return $response;
    }
    
    private function encodeLength($length) {
        if ($length < 0x80) {
            return chr($length);
        } else if ($length < 0x4000) {
            return chr(($length >> 8) | 0x80) . chr($length);
        } else if ($length < 0x200000) {
            return chr(($length >> 16) | 0xC0) . chr($length >> 8) . chr($length);
        } else if ($length < 0x10000000) {
            return chr(($length >> 24) | 0xE0) . chr($length >> 16) . chr($length >> 8) . chr($length);
        }
        return chr(0xF0) . chr($length >> 24) . chr($length >> 16) . chr($length >> 8) . chr($length);
    }
    
    private function decodeLength() {
        $byte = ord(fread($this->socket, 1));
        
        if ($byte < 0x80) {
            return $byte;
        } else if (($byte & 0xC0) == 0x80) {
            return (($byte & ~0xC0) << 8) + ord(fread($this->socket, 1));
        } else if (($byte & 0xE0) == 0xC0) {
            return (($byte & ~0xE0) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        } else if (($byte & 0xF0) == 0xE0) {
            return (($byte & ~0xF0) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        } else if (($byte & 0xF8) == 0xF0) {
            return (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        }
        
        return 0;
    }
    
    private function parseResponse($response) {
        $parsed = [];
        
        foreach ($response as $item) {
            $entry = [];
            foreach ($item as $line) {
                if (substr($line, 0, 1) === '=') {
                    $parts = explode('=', substr($line, 1), 2);
                    if (count($parts) === 2) {
                        $entry[$parts[0]] = $parts[1];
                    }
                }
            }
            if (!empty($entry)) {
                $parsed[] = $entry;
            }
        }
        
        return $parsed;
    }
    
    // ============================================
    // HOTSPOT USER MANAGEMENT
    // ============================================
    
    public function addHotspotUser($username, $password, $profile, $macAddress = '') {
        $params = [
            '=name=' . $username,
            '=password=' . $password,
            '=profile=' . $profile
        ];
        
        if (!empty($macAddress)) {
            $params[] = '=mac-address=' . $macAddress;
        }
        
        $this->write('/ip/hotspot/user/add', false, $params);
        $response = $this->read(false);
        
        return !isset($response[0]['!trap']);
    }
    
    public function removeHotspotUser($username) {
        // Find user ID
        $this->write('/ip/hotspot/user/print', false, ['?name=' . $username]);
        $users = $this->read(true);
        
        if (empty($users)) return false;
        
        $userId = $users[0]['.id'];
        
        $this->write('/ip/hotspot/user/remove', false, ['=.id=' . $userId]);
        $response = $this->read(false);
        
        return !isset($response[0]['!trap']);
    }
    
    public function getHotspotUsers() {
        $this->write('/ip/hotspot/user/print');
        return $this->read(true);
    }
    
    public function getHotspotActiveUsers() {
        $this->write('/ip/hotspot/active/print');
        return $this->read(true);
    }
    
    public function disconnectUser($id) {
        $this->write('/ip/hotspot/active/remove', false, ['=.id=' . $id]);
        $response = $this->read(false);
        return !isset($response[0]['!trap']);
    }
    
    // ============================================
    // HOTSPOT PROFILE MANAGEMENT
    // ============================================
    
    public function getHotspotProfiles() {
        $this->write('/ip/hotspot/user/profile/print');
        return $this->read(true);
    }
    
    public function addHotspotProfile($name, $rateLimit, $sessionTimeout) {
        $params = [
            '=name=' . $name,
            '=rate-limit=' . $rateLimit,
            '=session-timeout=' . $sessionTimeout
        ];
        
        $this->write('/ip/hotspot/user/profile/add', false, $params);
        $response = $this->read(false);
        
        return !isset($response[0]['!trap']);
    }
    
    // ============================================
    // SYSTEM INFORMATION
    // ============================================
    
    public function getSystemResource() {
        $this->write('/system/resource/print');
        return $this->read(true);
    }
    
    public function getSystemIdentity() {
        $this->write('/system/identity/print');
        $result = $this->read(true);
        return $result[0]['name'] ?? 'Unknown';
    }
    
    public function getInterfaces() {
        $this->write('/interface/print');
        return $this->read(true);
    }
    
    public function getSystemHealth() {
        $this->write('/system/health/print');
        return $this->read(true);
    }
    
    // ============================================
    // DHCP LEASES
    // ============================================
    
    public function getDhcpLeases() {
        $this->write('/ip/dhcp-server/lease/print');
        return $this->read(true);
    }
    
    // ============================================
    // LOGS
    // ============================================
    
    public function getSystemLogs($limit = 50) {
        $this->write('/log/print', false, ['?topics=~hotspot', '?count=' . $limit]);
        return $this->read(true);
    }
}

?>