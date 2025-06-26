<?php
/**
 * Garbicz Festival 2025 - Map Sync Automation System
 * 
 * Save this file as: sync.php
 * Upload to your web server root directory
 * 
 * @author Festival Tech Team
 * @version 2.0
 */

class GarbiczMapSync {
    
    private $config = [
        'github' => [
            'owner' => 'pankestudio',
            'repo' => 'GF25MAP',
            'branch' => 'main',
            'file_path' => 'garbicz-festival-map-2025.json',
            'token' => '', // GitHub Personal Access Token (set in environment)
        ],
        'backup_dir' => './backups/',
        'uploads_dir' => './uploads/',
        'max_file_size' => 5242880, // 5MB
        'allowed_origins' => [
            'https://garbiczfestival.com',
            'https://map.garbiczfestival.com',
            'http://localhost',
            'http://127.0.0.1'
        ]
    ];
    
    private $logger;
    
    public function __construct() {
        // Set GitHub token from environment variable for security
        $this->config['github']['token'] = $_ENV['GITHUB_TOKEN'] ?? '';
        
        // Create necessary directories
        $this->ensureDirectories();
        
        // Initialize logger
        $this->logger = new Logger();
        
        // Set CORS headers
        $this->setCorsHeaders();
    }
    
    /**
     * Main router - handles different API endpoints
     */
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $action = $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'sync':
                    return $this->handleSync();
                    
                case 'submit':
                    return $this->handleSubmission();
                    
                case 'fetch':
                    return $this->fetchLatestData();
                    
                case 'status':
                    return $this->getStatus();
                    
                case 'backup':
                    return $this->createBackup();
                    
                case 'validate':
                    return $this->validateMapData();
                    
                default:
                    return $this->sendResponse(['error' => 'Invalid action'], 400);
            }
        } catch (Exception $e) {
            $this->logger->error('Request failed', ['error' => $e->getMessage()]);
            return $this->sendResponse(['error' => 'Internal server error'], 500);
        }
    }
    
    /**
     * Fetch latest data from GitHub master file
     */
    public function fetchLatestData() {
        $this->logger->info('Fetching latest data from GitHub');
        
        $url = $this->getGitHubRawUrl();
        $data = $this->fetchFromUrl($url);
        
        if (!$data) {
            return $this->sendResponse(['error' => 'Failed to fetch data from GitHub'], 500);
        }
        
        $mapData = json_decode($data, true);
        if (!$mapData) {
            return $this->sendResponse(['error' => 'Invalid JSON data from GitHub'], 500);
        }
        
        // Validate structure
        $validation = $this->validateMapStructure($mapData);
        if (!$validation['valid']) {
            return $this->sendResponse(['error' => 'Invalid map structure: ' . $validation['error']], 500);
        }
        
        $this->logger->info('Successfully fetched latest data', [
            'locations_count' => $this->countLocations($mapData),
            'categories' => array_keys($mapData['locations'] ?? [])
        ]);
        
        return $this->sendResponse([
            'success' => true,
            'data' => $mapData,
            'timestamp' => date('c'),
            'source' => 'github_master'
        ]);
    }
    
    /**
     * Handle user submissions of map updates
     */
    public function handleSubmission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->sendResponse(['error' => 'POST method required'], 405);
        }
        
        $this->logger->info('Processing user submission');
        
        // Handle file upload or JSON data
        $mapData = null;
        
        if (isset($_FILES['map_file'])) {
            $mapData = $this->processFileUpload($_FILES['map_file']);
        } elseif (isset($_POST['map_data'])) {
            $mapData = json_decode($_POST['map_data'], true);
        } else {
            $input = file_get_contents('php://input');
            $mapData = json_decode($input, true);
        }
        
        if (!$mapData) {
            return $this->sendResponse(['error' => 'No valid map data provided'], 400);
        }
        
        // Validate submission
        $validation = $this->validateMapStructure($mapData);
        if (!$validation['valid']) {
            return $this->sendResponse(['error' => 'Invalid map structure: ' . $validation['error']], 400);
        }
        
        // Create backup before updating
        $backupId = $this->createBackup();
        
        // Process and merge with existing data
        $result = $this->mergeMapData($mapData);
        
        if ($result['success']) {
            $this->logger->info('Successfully processed submission', [
                'backup_id' => $backupId,
                'new_locations' => $result['stats']['new_locations'],
                'updated_locations' => $result['stats']['updated_locations']
            ]);
            
            return $this->sendResponse([
                'success' => true,
                'message' => 'Map data successfully updated',
                'backup_id' => $backupId,
                'stats' => $result['stats']
            ]);
        } else {
            return $this->sendResponse(['error' => $result['error']], 500);
        }
    }
    
    /**
     * Sync with GitHub - fetch latest and update if needed
     */
    public function handleSync() {
        $this->logger->info('Starting sync process');
        
        // Fetch current master data
        $masterData = $this->fetchLatestData();
        if (isset($masterData['error'])) {
            return $masterData;
        }
        
        // Check if local file exists and compare
        $localFile = $this->config['backup_dir'] . 'current.json';
        $needsUpdate = true;
        
        if (file_exists($localFile)) {
            $localData = json_decode(file_get_contents($localFile), true);
            $needsUpdate = $this->compareMapData($localData, $masterData['data']);
        }
        
        if ($needsUpdate) {
            // Update local copy
            file_put_contents($localFile, json_encode($masterData['data'], JSON_PRETTY_PRINT));
            
            $this->logger->info('Sync completed - data updated');
            return $this->sendResponse([
                'success' => true,
                'message' => 'Data synchronized successfully',
                'updated' => true,
                'timestamp' => date('c')
            ]);
        } else {
            $this->logger->info('Sync completed - no changes');
            return $this->sendResponse([
                'success' => true,
                'message' => 'Data already up to date',
                'updated' => false,
                'timestamp' => date('c')
            ]);
        }
    }
    
    /**
     * Create backup of current data
     */
    public function createBackup() {
        $timestamp = date('Y-m-d_H-i-s');
        $backupId = 'backup_' . $timestamp;
        
        // Fetch current master data
        $url = $this->getGitHubRawUrl();
        $data = $this->fetchFromUrl($url);
        
        if ($data) {
            $backupFile = $this->config['backup_dir'] . $backupId . '.json';
            file_put_contents($backupFile, $data);
            
            $this->logger->info('Backup created', ['backup_id' => $backupId]);
            
            // Clean old backups (keep last 50)
            $this->cleanOldBackups();
        }
        
        return $backupId;
    }
    
    /**
     * Validate map data structure
     */
    public function validateMapData() {
        $input = file_get_contents('php://input');
        $mapData = json_decode($input, true);
        
        if (!$mapData) {
            return $this->sendResponse(['error' => 'Invalid JSON data'], 400);
        }
        
        $validation = $this->validateMapStructure($mapData);
        
        return $this->sendResponse([
            'valid' => $validation['valid'],
            'error' => $validation['error'] ?? null,
            'stats' => $validation['valid'] ? [
                'total_locations' => $this->countLocations($mapData),
                'categories' => array_keys($mapData['locations'] ?? []),
                'version' => $mapData['version'] ?? 'unknown'
            ] : null
        ]);
    }
    
    /**
     * Get system status
     */
    public function getStatus() {
        $status = [
            'system' => 'Garbicz Map Sync v2.0',
            'timestamp' => date('c'),
            'github_connected' => !empty($this->config['github']['token']),
            'directories' => [
                'backups' => is_writable($this->config['backup_dir']),
                'uploads' => is_writable($this->config['uploads_dir'])
            ]
        ];
        
        // Check latest backup
        $backups = glob($this->config['backup_dir'] . '*.json');
        if (!empty($backups)) {
            $latest = max($backups);
            $status['last_backup'] = [
                'file' => basename($latest),
                'timestamp' => filemtime($latest),
                'size' => filesize($latest)
            ];
        }
        
        // Test GitHub connection
        if (!empty($this->config['github']['token'])) {
            $testUrl = $this->getGitHubRawUrl();
            $testData = $this->fetchFromUrl($testUrl);
            $status['github_test'] = !empty($testData);
        }
        
        return $this->sendResponse($status);
    }
    
    /**
     * Update master file on GitHub
     */
    private function updateGitHubFile($mapData, $message = 'Update festival map data') {
        if (empty($this->config['github']['token'])) {
            throw new Exception('GitHub token not configured');
        }
        
        $owner = $this->config['github']['owner'];
        $repo = $this->config['github']['repo'];
        $path = $this->config['github']['file_path'];
        
        // Get current file SHA
        $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$path}";
        $current = $this->fetchFromUrl($apiUrl, [
            'Authorization: token ' . $this->config['github']['token'],
            'User-Agent: Garbicz-Map-Sync/2.0'
        ]);
        
        $currentData = json_decode($current, true);
        $sha = $currentData['sha'] ?? null;
        
        // Prepare update data
        $content = base64_encode(json_encode($mapData, JSON_PRETTY_PRINT));
        $updateData = [
            'message' => $message,
            'content' => $content,
            'branch' => $this->config['github']['branch']
        ];
        
        if ($sha) {
            $updateData['sha'] = $sha;
        }
        
        // Send update
        $response = $this->postToUrl($apiUrl, json_encode($updateData), [
            'Authorization: token ' . $this->config['github']['token'],
            'User-Agent: Garbicz-Map-Sync/2.0',
            'Content-Type: application/json'
        ]);
        
        return json_decode($response, true);
    }
    
    /**
     * Merge new map data with existing data
     */
    private function mergeMapData($newData) {
        try {
            // Fetch current master data
            $masterResponse = $this->fetchLatestData();
            if (isset($masterResponse['error'])) {
                throw new Exception('Failed to fetch master data');
            }
            
            $masterData = $masterResponse['data'];
            $stats = ['new_locations' => 0, 'updated_locations' => 0];
            
            // Merge locations by category
            foreach ($newData['locations'] as $category => $locations) {
                if (!isset($masterData['locations'][$category])) {
                    $masterData['locations'][$category] = [];
                }
                
                foreach ($locations as $newLocation) {
                    $found = false;
                    
                    // Look for existing location by name and approximate coordinates
                    foreach ($masterData['locations'][$category] as &$existingLocation) {
                        if ($this->isSameLocation($newLocation, $existingLocation)) {
                            $existingLocation = array_merge($existingLocation, $newLocation);
                            $stats['updated_locations']++;
                            $found = true;
                            break;
                        }
                    }
                    
                    if (!$found) {
                        $masterData['locations'][$category][] = $newLocation;
                        $stats['new_locations']++;
                    }
                }
            }
            
            // Update metadata
            $masterData['version'] = '2.0';
            $masterData['lastUpdated'] = date('c');
            $masterData['metadata']['totalLocations'] = $this->countLocations($masterData);
            
            // Update GitHub file
            $commitMessage = "Auto-update: +{$stats['new_locations']} new, ~{$stats['updated_locations']} updated locations";
            $result = $this->updateGitHubFile($masterData, $commitMessage);
            
            return [
                'success' => true,
                'stats' => $stats,
                'github_response' => $result
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Merge failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate map data structure
     */
    private function validateMapStructure($data) {
        if (!is_array($data)) {
            return ['valid' => false, 'error' => 'Data must be an array'];
        }
        
        if (!isset($data['locations'])) {
            return ['valid' => false, 'error' => 'Missing locations object'];
        }
        
        if (!is_array($data['locations'])) {
            return ['valid' => false, 'error' => 'Locations must be an array'];
        }
        
        // Validate each category
        foreach ($data['locations'] as $category => $locations) {
            if (!is_array($locations)) {
                return ['valid' => false, 'error' => "Category {$category} must be an array"];
            }
            
            foreach ($locations as $i => $location) {
                $validation = $this->validateLocation($location, "{$category}[{$i}]");
                if (!$validation['valid']) {
                    return $validation;
                }
            }
        }
        
        return ['valid' => true];
    }
    
    /**
     * Validate individual location
     */
    private function validateLocation($location, $path = '') {
        $required = ['name', 'lat', 'lng'];
        
        foreach ($required as $field) {
            if (!isset($location[$field])) {
                return ['valid' => false, 'error' => "Missing required field '{$field}' in {$path}"];
            }
        }
        
        // Validate coordinates
        if (!is_numeric($location['lat']) || !is_numeric($location['lng'])) {
            return ['valid' => false, 'error' => "Invalid coordinates in {$path}"];
        }
        
        if ($location['lat'] < -90 || $location['lat'] > 90) {
            return ['valid' => false, 'error' => "Invalid latitude in {$path}"];
        }
        
        if ($location['lng'] < -180 || $location['lng'] > 180) {
            return ['valid' => false, 'error' => "Invalid longitude in {$path}"];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Check if two locations are the same
     */
    private function isSameLocation($loc1, $loc2) {
        // Same name
        if (trim(strtolower($loc1['name'])) === trim(strtolower($loc2['name']))) {
            return true;
        }
        
        // Same coordinates (within 10 meters)
        $distance = $this->calculateDistance($loc1['lat'], $loc1['lng'], $loc2['lat'], $loc2['lng']);
        return $distance < 0.00009; // Approximately 10 meters
    }
    
    /**
     * Calculate distance between two coordinates
     */
    private function calculateDistance($lat1, $lng1, $lat2, $lng2) {
        return sqrt(pow($lat2 - $lat1, 2) + pow($lng2 - $lng1, 2));
    }
    
    /**
     * Count total locations
     */
    private function countLocations($data) {
        $count = 0;
        foreach ($data['locations'] as $locations) {
            $count += count($locations);
        }
        return $count;
    }
    
    /**
     * Compare two map datasets
     */
    private function compareMapData($data1, $data2) {
        return json_encode($data1) !== json_encode($data2);
    }
    
    /**
     * Process file upload
     */
    private function processFileUpload($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $file['error']);
        }
        
        if ($file['size'] > $this->config['max_file_size']) {
            throw new Exception('File too large');
        }
        
        if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'json') {
            throw new Exception('Only JSON files allowed');
        }
        
        $content = file_get_contents($file['tmp_name']);
        return json_decode($content, true);
    }
    
    /**
     * Get GitHub raw file URL
     */
    private function getGitHubRawUrl() {
        $owner = $this->config['github']['owner'];
        $repo = $this->config['github']['repo'];
        $branch = $this->config['github']['branch'];
        $path = $this->config['github']['file_path'];
        
        return "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$path}";
    }
    
    /**
     * Fetch data from URL
     */
    private function fetchFromUrl($url, $headers = []) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", array_merge([
                    'User-Agent: Garbicz-Map-Sync/2.0'
                ], $headers)),
                'timeout' => 30
            ]
        ]);
        
        return file_get_contents($url, false, $context);
    }
    
    /**
     * POST data to URL
     */
    private function postToUrl($url, $data, $headers = []) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", array_merge([
                    'User-Agent: Garbicz-Map-Sync/2.0'
                ], $headers)),
                'content' => $data,
                'timeout' => 30
            ]
        ]);
        
        return file_get_contents($url, false, $context);
    }
    
    /**
     * Clean old backup files
     */
    private function cleanOldBackups($keep = 50) {
        $backups = glob($this->config['backup_dir'] . 'backup_*.json');
        
        if (count($backups) > $keep) {
            // Sort by modification time
            usort($backups, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            // Remove old backups
            $toRemove = array_slice($backups, $keep);
            foreach ($toRemove as $file) {
                unlink($file);
            }
        }
    }
    
    /**
     * Ensure necessary directories exist
     */
    private function ensureDirectories() {
        $dirs = [$this->config['backup_dir'], $this->config['uploads_dir']];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Set CORS headers
     */
    private function setCorsHeaders() {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (in_array($origin, $this->config['allowed_origins'])) {
            header("Access-Control-Allow-Origin: {$origin}");
        }
        
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Credentials: true');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit(0);
        }
    }
    
    /**
     * Send JSON response
     */
    private function sendResponse($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit();
    }
}

/**
 * Simple logger class
 */
class Logger {
    private $logFile;
    
    public function __construct($logFile = './logs/sync.log') {
        $this->logFile = $logFile;
        $this->ensureLogDirectory();
    }
    
    public function info($message, $context = []) {
        $this->log('INFO', $message, $context);
    }
    
    public function error($message, $context = []) {
        $this->log('ERROR', $message, $context);
    }
    
    private function log($level, $message, $context = []) {
        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
        
        file_put_contents($this->logFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    private function ensureLogDirectory() {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

// Main execution
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $sync = new GarbiczMapSync();
    $sync->handleRequest();
}
?>
