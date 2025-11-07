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

    // =====================================================
    // CONNECTION
    // =====================================================

    public function connect() {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$this->socket) {
            logMessage("MikroTik connection failed: $errstr ($errno)", 'ERROR');
            return false;
        }

        $this->connected = true;

        // Login
        $this->write('/login');
        $response = $this->read();

        if (isset($response[0]['!trap'])) {
            logMessage("MikroTik login failed", 'ERROR');
            return false;
        }

        $this->write('/login', false, ['=name=' . $this->user, '=password=' . $this->pass]);
        $response = $this->read();

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

    // =====================================================
    // LOW-LEVEL IO
    // =====================================================

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

        if ($parse) return $this->parseResponse($response);
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
        if ($byte < 0x80) return $byte;
        if (($byte & 0xC0) == 0x80) return (($byte & ~0xC0) << 8) + ord(fread($this->socket, 1));
        if (($byte & 0xE0) == 0xC0)
            return (($byte & ~0xE0) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        if (($byte & 0xF0) == 0xE0)
            return (($byte & ~0xF0) << 24) + (ord(fread($this->socket, 1)) << 16)
                + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        if (($byte & 0xF8) == 0xF0)
            return (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16)
                + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        return 0;
    }

    private function parseResponse($response) {
        $parsed = [];
        foreach ($response as $item) {
            $entry = [];
            foreach ($item as $line) {
                if (substr($line, 0, 1) === '=') {
                    $parts = explode('=', substr($line, 1), 2);
                    if (count($parts) === 2) $entry[$parts[0]] = $parts[1];
                }
            }
            if (!empty($entry)) $parsed[] = $entry;
        }
        return $parsed;
    }

    // =====================================================
    // HOTSPOT MANAGEMENT
    // =====================================================

    public function addHotspotUser($username, $password, $profile, $macAddress = '', $ipAddress = '') {
        $params = [
            '=name=' . $username,
            '=password=' . $password,
            '=profile=' . $profile
        ];
        if (!empty($macAddress)) $params[] = '=comment=' . $macAddress;

        $this->write('/ip/hotspot/user/add', false, $params);
        $response = $this->read(false);

        if (isset($response[0]['!trap'])) {
            logMessage("Failed to add hotspot user: $username", 'ERROR');
            return false;
        }

        logMessage("Hotspot user $username added successfully", 'INFO');

        // Attempt automatic login
        if (!empty($ipAddress) && !empty($macAddress)) {
            $loginSuccess = $this->hotspotLogin($ipAddress, $macAddress, $username, $password);
            if ($loginSuccess) {
                logMessage("User $username logged in automatically", 'INFO');
            } else {
                logMessage("Automatic login failed for $username", 'WARNING');
            }
        }

        return true;
    }

    public function removeHotspotUser($username) {
        $this->write('/ip/hotspot/user/print', false, ['?name=' . $username]);
        $users = $this->read(true);
        if (empty($users)) return false;

        $userId = $users[0]['.id'] ?? null;
        if (!$userId) return false;

        $this->write('/ip/hotspot/user/remove', false, ['=.id=' . $userId]);
        $response = $this->read(false);
        return !isset($response[0]['!trap']);
    }

    public function hotspotLogin($ip, $mac, $username, $password) {
        $this->write('/ip/hotspot/active/login', false, [
            '=user=' . $username,
            '=password=' . $password,
            '=ip=' . $ip,
            '=mac-address=' . $mac
        ]);
        $response = $this->read(false);

        if (!isset($response[0]['!trap'])) {
            logMessage("Hotspot login successful for $username", 'INFO');
            return true;
        }

        return false;
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

    // =====================================================
    // SYSTEM INFO
    // =====================================================

    public function getSystemIdentity() {
        $this->write('/system/identity/print');
        $result = $this->read(true);
        return $result[0]['name'] ?? 'Uptime';
    }

    public function getSystemResource() {
        $this->write('/system/resource/print');
        return $this->read(true);
    }

    public function getInterfaces() {
        $this->write('/interface/print');
        return $this->read(true);
    }

    public function getSystemLogs($limit = 50) {
        $this->write('/log/print', false, ['?count=' . $limit]);
        return $this->read(true);
    }

    // =====================================================
    // HOTSPOT PROFILES
    // =====================================================

    public function getHotspotProfiles() {
        if (!$this->connected) {
            $this->connect();
        }

        $this->write('/ip/hotspot/user/profile/print');
        $profiles = $this->read();

        $result = [];
        foreach ($profiles as $profile) {
            if (isset($profile['.id'], $profile['name'])) {
                $result[] = [
                    'id' => $profile['.id'],
                    'name' => $profile['name']
                ];
            }
        }

        return $result;
    }

    // =====================================================
    // BANDWIDTH & TRAFFIC MONITORING (NEW)
    // =====================================================

    /**
     * Get interface traffic statistics
     */
    public function getInterfaceStats() {
        $this->write('/interface/print', false, ['=stats']);
        return $this->read(true);
    }

    /**
     * Get detailed traffic statistics per interface
     */
    public function getTrafficStats() {
        $this->write('/interface/monitor-traffic', false, ['=interface=all', '=once']);
        return $this->read(true);
    }

    /**
     * Get hotspot user session history
     */
    public function getHotspotCookies() {
        $this->write('/ip/hotspot/cookie/print');
        return $this->read(true);
    }

    /**
     * Get bandwidth usage for active users with detailed stats
     */
    public function getActiveUsersBandwidth() {
        $activeUsers = $this->getHotspotActiveUsers();
        
        foreach ($activeUsers as &$user) {
            // Parse bytes in/out if available
            if (isset($user['bytes-in'])) {
                $user['bytes-in-formatted'] = $this->formatBytes($user['bytes-in']);
            }
            if (isset($user['bytes-out'])) {
                $user['bytes-out-formatted'] = $this->formatBytes($user['bytes-out']);
            }
            if (isset($user['bytes-in']) && isset($user['bytes-out'])) {
                $total = $user['bytes-in'] + $user['bytes-out'];
                $user['total-bytes'] = $total;
                $user['total-bytes-formatted'] = $this->formatBytes($total);
            }
        }
        
        return $activeUsers;
    }

    // =====================================================
    // QUEUE MANAGEMENT (NEW)
    // =====================================================

    /**
     * Get simple queues (bandwidth limits)
     */
    public function getSimpleQueues() {
        $this->write('/queue/simple/print');
        return $this->read(true);
    }

    /**
     * Add bandwidth limit for a user
     */
    public function addBandwidthLimit($target, $maxUpload, $maxDownload, $name = '') {
        $queueName = !empty($name) ? $name : "limit-$target";
        
        $params = [
            '=name=' . $queueName,
            '=target=' . $target,
            '=max-limit=' . $maxUpload . '/' . $maxDownload
        ];
        
        $this->write('/queue/simple/add', false, $params);
        $response = $this->read(false);
        
        return !isset($response[0]['!trap']);
    }

    /**
     * Remove bandwidth limit
     */
    public function removeBandwidthLimit($queueId) {
        $this->write('/queue/simple/remove', false, ['=.id=' . $queueId]);
        $response = $this->read(false);
        return !isset($response[0]['!trap']);
    }

    // =====================================================
    // DHCP MANAGEMENT (NEW)
    // =====================================================

    /**
     * Get DHCP leases
     */
    public function getDhcpLeases() {
        $this->write('/ip/dhcp-server/lease/print');
        return $this->read(true);
    }

    /**
     * Get DHCP server statistics
     */
    public function getDhcpServers() {
        $this->write('/ip/dhcp-server/print');
        return $this->read(true);
    }

    // =====================================================
    // FIREWALL & ACCESS CONTROL (NEW)
    // =====================================================

    /**
     * Get firewall filter rules
     */
    public function getFirewallRules() {
        $this->write('/ip/firewall/filter/print');
        return $this->read(true);
    }

    /**
     * Get firewall address lists (for blocking/allowing IPs)
     */
    public function getFirewallAddressLists() {
        $this->write('/ip/firewall/address-list/print');
        return $this->read(true);
    }

    /**
     * Add IP to address list (for blocking/whitelisting)
     */
    public function addToAddressList($address, $listName, $comment = '') {
        $params = [
            '=address=' . $address,
            '=list=' . $listName
        ];
        
        if (!empty($comment)) {
            $params[] = '=comment=' . $comment;
        }
        
        $this->write('/ip/firewall/address-list/add', false, $params);
        $response = $this->read(false);
        
        return !isset($response[0]['!trap']);
    }

    /**
     * Remove IP from address list
     */
    public function removeFromAddressList($id) {
        $this->write('/ip/firewall/address-list/remove', false, ['=.id=' . $id]);
        $response = $this->read(false);
        return !isset($response[0]['!trap']);
    }

    /**
     * Block an IP address
     */
    public function blockIpAddress($ip, $comment = 'Blocked from admin') {
        return $this->addToAddressList($ip, 'blocked', $comment);
    }

    /**
     * Block a MAC address
     */
    public function blockMacAddress($mac, $comment = 'Blocked from admin') {
        $params = [
            '=mac-address=' . $mac,
            '=comment=' . $comment
        ];
        
        $this->write('/interface/wireless/access-list/add', false, $params);
        $response = $this->read(false);
        
        return !isset($response[0]['!trap']);
    }

    // =====================================================
    // USER PROFILE MANAGEMENT (NEW)
    // =====================================================

    /**
     * Update hotspot user profile
     */
    public function updateUserProfile($username, $newProfile) {
        $this->write('/ip/hotspot/user/print', false, ['?name=' . $username]);
        $users = $this->read(true);
        
        if (empty($users)) return false;
        
        $userId = $users[0]['.id'] ?? null;
        if (!$userId) return false;
        
        $this->write('/ip/hotspot/user/set', false, [
            '=.id=' . $userId,
            '=profile=' . $newProfile
        ]);
        $response = $this->read(false);
        
        return !isset($response[0]['!trap']);
    }

    /**
     * Update hotspot user password
     */
    public function updateUserPassword($username, $newPassword) {
        $this->write('/ip/hotspot/user/print', false, ['?name=' . $username]);
        $users = $this->read(true);
        
        if (empty($users)) return false;
        
        $userId = $users[0]['.id'] ?? null;
        if (!$userId) return false;
        
        $this->write('/ip/hotspot/user/set', false, [
            '=.id=' . $userId,
            '=password=' . $newPassword
        ]);
        $response = $this->read(false);
        
        return !isset($response[0]['!trap']);
    }

    // =====================================================
    // ADVANCED STATISTICS (NEW)
    // =====================================================

    /**
     * Get router CPU and memory usage over time
     */
    public function getSystemHealth() {
        $this->write('/system/health/print');
        $health = $this->read(true);
        
        $this->write('/system/resource/print');
        $resource = $this->read(true);
        
        return [
            'health' => $health,
            'resource' => $resource
        ];
    }

    /**
     * Get total data usage statistics
     */
    public function getTotalDataUsage() {
        $interfaces = $this->getInterfaceStats();
        
        $totalRx = 0;
        $totalTx = 0;
        
        foreach ($interfaces as $iface) {
            if (isset($iface['rx-byte'])) {
                $totalRx += intval($iface['rx-byte']);
            }
            if (isset($iface['tx-byte'])) {
                $totalTx += intval($iface['tx-byte']);
            }
        }
        
        return [
            'total_received' => $totalRx,
            'total_transmitted' => $totalTx,
            'total_received_formatted' => $this->formatBytes($totalRx),
            'total_transmitted_formatted' => $this->formatBytes($totalTx),
            'total_formatted' => $this->formatBytes($totalRx + $totalTx)
        ];
    }

    /**
     * Get connection tracking statistics
     */
    public function getConnectionStats() {
        $this->write('/ip/firewall/connection/print', false, ['=count-only']);
        return $this->read(true);
    }

    // =====================================================
    // BACKUP & RESTORE (NEW)
    // =====================================================

    /**
     * Create system backup
     */
    public function createBackup($name = '') {
        $backupName = !empty($name) ? $name : 'backup-' . date('Y-m-d-His');
        
        $this->write('/system/backup/save', false, ['=name=' . $backupName]);
        $response = $this->read(false);
        
        return !isset($response[0]['!trap']) ? $backupName : false;
    }

    /**
     * List available backups
     */
    public function listBackups() {
        $this->write('/file/print', false, ['?type=backup']);
        return $this->read(true);
    }

    // =====================================================
    // UTILITY FUNCTIONS (NEW)
    // =====================================================

    /**
     * Format bytes to human readable format
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Ping a host
     */
    public function ping($host, $count = 4) {
        $this->write('/ping', false, [
            '=address=' . $host,
            '=count=' . $count
        ]);
        return $this->read(true);
    }

    /**
     * Get router clock/time
     */
    public function getSystemClock() {
        $this->write('/system/clock/print');
        return $this->read(true);
    }

    /**
     * Reboot router (use with caution!)
     */
    public function rebootRouter() {
        $this->write('/system/reboot');
        $response = $this->read(false);
        return !isset($response[0]['!trap']);
    }
}
?>