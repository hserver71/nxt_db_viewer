<?php
/**
 * Simple Database Viewer for NXT Panel
 * Similar to phpMyAdmin - shows tables and records
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load configuration if exists
$configFile = __DIR__ . '/dbviewer_config.php';
$config = [];
if (file_exists($configFile)) {
    $config = require $configFile;
} else {
    // Default config
    $config = [
        'type' => 'mysqli',
        'host' => 'localhost',
        'port' => 7999,
        'username' => '',
        'password' => '',
        'database' => '',
        'socket' => '',
        'auto_detect' => true,
        'use_config_file' => true,  // Try to use /home/nxt/config
    ];
}

// Load the connection if auto-detect is enabled
if ($config['auto_detect'] ?? true) {
    // Set server variables needed for App.php - it might check the script name
    if (!isset($_SERVER['REQUEST_SCHEME'])) {
        $_SERVER['REQUEST_SCHEME'] = 'http';
    }
    if (!isset($_SERVER['HTTP_HOST'])) {
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
    }
    // Set SCRIPT_NAME to match how the application expects it
    if (!isset($_SERVER['SCRIPT_NAME'])) {
        $_SERVER['SCRIPT_NAME'] = '/public/dbviewer.php';
    }
    if (!isset($_SERVER['PHP_SELF'])) {
        $_SERVER['PHP_SELF'] = '/public/dbviewer.php';
    }
    if (!isset($_SERVER['REQUEST_URI'])) {
        $_SERVER['REQUEST_URI'] = '/dbviewer.php';
    }
    
    require_once __DIR__ . '/../app/libs/connection.php';
    
    // Load App.php - it might create the connection automatically based on script name
    if (file_exists(__DIR__ . '/../app/libs/App.php')) {
        try {
            require_once __DIR__ . '/../app/libs/App.php';
        } catch (Exception $e) {
            // Continue without App.php
        }
    }
    
    require_once __DIR__ . '/../app/libs/helpers.php';
    
    // Try to read and decrypt config file if it exists
    if (($config['use_config_file'] ?? true) && file_exists('/home/nxt/config')) {
        $encryptedConfig = file_get_contents('/home/nxt/config');
        
        // Try to decrypt using decrypt_with_migration function (this is the correct one!)
        if (function_exists('decrypt_with_migration') && !empty($encryptedConfig)) {
            try {
                $decryptedConfig = decrypt_with_migration($encryptedConfig);
                
                if (!empty($decryptedConfig)) {
                    // The decrypted config is JSON format
                    $json = json_decode($decryptedConfig, true);
                    if ($json && is_array($json)) {
                        // Parse JSON format: {"host":"...","db_port":...,"db_user":"...","db_pass":"...","db_name":"..."}
                        $config['host'] = $json['host'] ?? $json['hostname'] ?? 'localhost';
                        $config['port'] = $json['db_port'] ?? $json['port'] ?? 7999;
                        $config['username'] = $json['db_user'] ?? $json['user'] ?? $json['username'] ?? '';
                        $config['password'] = $json['db_pass'] ?? $json['pass'] ?? $json['password'] ?? '';
                        $config['database'] = $json['db_name'] ?? $json['db'] ?? $json['database'] ?? $json['dbname'] ?? '';
                    } else {
                        // If not JSON, try colon-separated format
                        $separators = [':', '|', ',', ';', "\n", "\t"];
                        $parts = [];
                        
                        foreach ($separators as $sep) {
                            if (strpos($decryptedConfig, $sep) !== false) {
                                $parts = explode($sep, $decryptedConfig);
                                break;
                            }
                        }
                        
                        if (count($parts) >= 4) {
                            $config['username'] = trim($parts[0] ?? '');
                            $config['password'] = trim($parts[1] ?? '');
                            $config['database'] = trim($parts[2] ?? '');
                            $config['host'] = trim($parts[3] ?? 'localhost');
                            $config['port'] = isset($parts[4]) ? intval(trim($parts[4])) : 7999;
                        }
                    }
                }
            } catch (Exception $e) {
                // Decryption failed, will use manual config
                // Don't set error here, let it try manual config
            } catch (Error $e) {
                // Also catch fatal errors
            }
        }
        // Fallback to db_crypt if decrypt_with_migration doesn't exist
        elseif (function_exists('db_crypt') && !empty($encryptedConfig)) {
            try {
                $decryptedConfig = db_crypt($encryptedConfig);
                if (empty($decryptedConfig) && substr($encryptedConfig, 0, 3) === 'S1:') {
                    $decryptedConfig = db_crypt(substr($encryptedConfig, 3));
                }
                
                if (!empty($decryptedConfig)) {
                    $json = json_decode($decryptedConfig, true);
                    if ($json && is_array($json)) {
                        $config['host'] = $json['host'] ?? $json['hostname'] ?? 'localhost';
                        $config['port'] = $json['db_port'] ?? $json['port'] ?? 7999;
                        $config['username'] = $json['db_user'] ?? $json['user'] ?? $json['username'] ?? '';
                        $config['password'] = $json['db_pass'] ?? $json['pass'] ?? $json['password'] ?? '';
                        $config['database'] = $json['db_name'] ?? $json['db'] ?? $json['database'] ?? $json['dbname'] ?? '';
                    }
                }
            } catch (Exception $e) {
                // Decryption failed
            } catch (Error $e) {
                // Also catch fatal errors
            }
        }
    }
}

// App.php is already loaded above, so mark it as loaded
$appLoaded = file_exists(__DIR__ . '/../app/libs/App.php');

// Try to get database connection
$db = null;
$connectionError = null;

// First, check if App.php created a connection automatically
// Look for nGyAM instances that might have been created by App.php
foreach ($GLOBALS as $name => $value) {
    if (in_array($name, ['GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', 'argv', 'argc'])) {
        continue;
    }
    
    if (is_object($value)) {
        $class = get_class($value);
        // Check for nGyAM instances (the connection class)
        if ($class === 'nGyAM') {
            // Try to get the mysqli connection from nGyAM
            $ref = new ReflectionObject($value);
            $props = $ref->getProperties();
            foreach ($props as $p) {
                $p->setAccessible(true);
                $val = $p->getValue($value);
                if (is_object($val) && (get_class($val) === 'mysqli' || is_subclass_of($val, 'mysqli'))) {
                    $db = $val;
                    break 2;
                }
            }
            // If nGyAM has credentials, try to connect
            $hasCredentials = false;
            foreach ($props as $p) {
                $p->setAccessible(true);
                $val = $p->getValue($value);
                if (is_string($val) && strlen($val) > 0 && strlen($val) < 200) {
                    $hasCredentials = true;
                }
            }
            if ($hasCredentials) {
                try {
                    $result = $value->db_connect();
                    if ($result) {
                        $ref = new ReflectionObject($value);
                        $props = $ref->getProperties();
                        foreach ($props as $p) {
                            $p->setAccessible(true);
                            $val = $p->getValue($value);
                            if (is_object($val) && get_class($val) === 'mysqli') {
                                $db = $val;
                                break 2;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Continue searching
                }
            }
        }
        // Check for direct mysqli/PDO connections
        if ($class === 'mysqli' || is_subclass_of($value, 'mysqli')) {
            $db = $value;
            break;
        }
        if ($class === 'PDO' || is_subclass_of($value, 'PDO')) {
            $db = $value;
            break;
        }
    }
}

// Try to get connection from App class methods
if (!$db && $appLoaded) {
    $classes = get_declared_classes();
    foreach ($classes as $className) {
        if (stripos($className, 'App') !== false && $className !== 'AppendIterator') {
            // Try getInstance() if available
            if (method_exists($className, 'getInstance')) {
                try {
                    $inst = $className::getInstance();
                    $ref = new ReflectionObject($inst);
                    $props = $ref->getProperties();
                    foreach ($props as $p) {
                        $p->setAccessible(true);
                        $val = $p->getValue($inst);
                        if (is_object($val)) {
                            if (get_class($val) === 'nGyAM') {
                                // Extract mysqli from nGyAM
                                $refDb = new ReflectionObject($val);
                                $propsDb = $refDb->getProperties();
                                foreach ($propsDb as $pDb) {
                                    $pDb->setAccessible(true);
                                    $valDb = $pDb->getValue($val);
                                    if (is_object($valDb) && get_class($valDb) === 'mysqli') {
                                        $db = $valDb;
                                        break 2;
                                    }
                                }
                            } elseif (get_class($val) === 'mysqli' || get_class($val) === 'PDO') {
                                $db = $val;
                                break 2;
                            }
                        }
                    }
                } catch (Exception $e) {}
            }
            // Try getConnection() if available
            if (method_exists($className, 'getConnection')) {
                try {
                    $conn = $className::getConnection();
                    if (is_object($conn)) {
                        if (get_class($conn) === 'mysqli' || get_class($conn) === 'PDO') {
                            $db = $conn;
                            break;
                        } elseif (get_class($conn) === 'nGyAM') {
                            // Extract mysqli from nGyAM
                            $refDb = new ReflectionObject($conn);
                            $propsDb = $refDb->getProperties();
                            foreach ($propsDb as $pDb) {
                                $pDb->setAccessible(true);
                                $valDb = $pDb->getValue($conn);
                                if (is_object($valDb) && get_class($valDb) === 'mysqli') {
                                    $db = $valDb;
                                    break 2;
                                }
                            }
                        }
                    }
                } catch (Exception $e) {}
            }
        }
    }
}

// Try to get connection from App class if it exists
if (!$db && $appLoaded) {
    $classes = get_declared_classes();
    foreach ($classes as $className) {
        if (stripos($className, 'App') !== false || stripos($className, 'Connection') !== false) {
            // Try static methods
            if (method_exists($className, 'getConnection')) {
                try {
                    $conn = $className::getConnection();
                    if (is_object($conn) && (get_class($conn) === 'mysqli' || get_class($conn) === 'PDO')) {
                        $db = $conn;
                        break;
                    }
                } catch (Exception $e) {}
            }
            if (method_exists($className, 'getInstance')) {
                try {
                    $inst = $className::getInstance();
                    if (is_object($inst) && method_exists($inst, 'getConnection')) {
                        $conn = $inst->getConnection();
                        if (is_object($conn) && (get_class($conn) === 'mysqli' || get_class($conn) === 'PDO')) {
                            $db = $conn;
                            break;
                        }
                    }
                } catch (Exception $e) {}
            }
        }
    }
}

// If no connection found, try to create one using defaults
if (!$db) {
    // Try to find connection through reflection or function calls
    $functions = get_defined_functions()['user'];
    foreach ($functions as $func) {
        if (stripos($func, 'connect') !== false || stripos($func, 'db') !== false) {
            try {
                // Skip functions that require parameters (like db_crypt)
                $ref = new ReflectionFunction($func);
                if ($ref->getNumberOfRequiredParameters() > 0) {
                    continue; // Skip functions that need parameters
                }
                $result = @call_user_func($func);
                if (is_object($result) && (get_class($result) === 'mysqli' || get_class($result) === 'PDO')) {
                    $db = $result;
                    break;
                }
            } catch (Exception $e) {
                // Ignore
            } catch (Error $e) {
                // Ignore fatal errors too
            }
        }
    }
    
    // Try to create connection using decrypted config
    if (!$db && !empty($config['host']) && !empty($config['username'])) {
        try {
            if ($config['type'] === 'pdo') {
                $dsn = "mysql:host={$config['host']};port={$config['port']}";
                if (!empty($config['database'])) {
                    $dsn .= ";dbname=" . $config['database'];
                }
                $db = new PDO($dsn, $config['username'], $config['password']);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } else {
                // MySQLi
                $db = @new mysqli($config['host'], $config['username'], $config['password'], $config['database'] ?? '', $config['port']);
                
                if ($db->connect_error) {
                    $connectionError = "MySQLi connection failed: " . $db->connect_error;
                    $db = null;
                }
            }
        } catch (Exception $e) {
            $connectionError = "Connection error: " . $e->getMessage();
        }
    }
    
    // Last resort: try manual configuration
    if (!$db && !($config['auto_detect'] ?? true)) {
        try {
            $host = $config['host'] ?? 'localhost';
            $port = intval($config['port'] ?? 3306);
            $user = $config['username'] ?? '';
            $pass = $config['password'] ?? '';
            $socket = $config['socket'] ?? '';
            
            if ($config['type'] === 'pdo') {
                if ($socket) {
                    $dsn = "mysql:unix_socket=$socket";
                } else {
                    $dsn = "mysql:host=$host;port=$port";
                }
                if (!empty($config['database'])) {
                    $dsn .= ";dbname=" . $config['database'];
                }
                $db = new PDO($dsn, $user, $pass);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } else {
                // MySQLi
                if ($socket) {
                    $db = @new mysqli(null, $user, $pass, $config['database'] ?? '', 0, $socket);
                } else {
                    $db = @new mysqli($host, $user, $pass, $config['database'] ?? '', $port);
                }
                
                if ($db->connect_error) {
                    $connectionError = "MySQLi connection failed: " . $db->connect_error;
                    $db = null;
                }
            }
        } catch (Exception $e) {
            $connectionError = "Connection error: " . $e->getMessage();
        }
    } elseif (!$db) {
        $connectionError = "Could not automatically detect database connection. ";
        $connectionError .= "Please edit /home/nxt/public/dbviewer_config.php to configure the connection manually.";
    }
}

// Get current table and page
$currentTable = $_GET['table'] ?? '';
$currentPage = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($currentPage - 1) * $perPage;

// Get tables list
$tables = [];
$databases = [];
if ($db) {
    // First, get list of databases if no specific database is set
    if (empty($config['database'])) {
        if ($db instanceof mysqli) {
            $result = $db->query("SHOW DATABASES");
            if ($result) {
                while ($row = $result->fetch_array()) {
                    $dbName = $row[0];
                    // Filter out system databases
                    if (!in_array($dbName, ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                        $databases[] = $dbName;
                    }
                }
                $result->free();
            }
            // Use first database or show database selector
            if (!empty($databases) && empty($_GET['db'])) {
                $selectedDb = $databases[0];
                $db->select_db($selectedDb);
            } elseif (!empty($_GET['db'])) {
                $selectedDb = $_GET['db'];
                $db->select_db($selectedDb);
            }
        } elseif ($db instanceof PDO) {
            $stmt = $db->query("SHOW DATABASES");
            if ($stmt) {
                $allDbs = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($allDbs as $dbName) {
                    if (!in_array($dbName, ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                        $databases[] = $dbName;
                    }
                }
            }
            if (!empty($databases) && empty($_GET['db'])) {
                $selectedDb = $databases[0];
                $db->exec("USE `" . str_replace('`', '``', $selectedDb) . "`");
            } elseif (!empty($_GET['db'])) {
                $selectedDb = $_GET['db'];
                $db->exec("USE `" . str_replace('`', '``', $selectedDb) . "`");
            }
        }
    }
    
    // Get tables
    if ($db instanceof mysqli) {
        $result = $db->query("SHOW TABLES");
        if ($result) {
            while ($row = $result->fetch_array()) {
                $tables[] = $row[0];
            }
            $result->free();
        }
    } elseif ($db instanceof PDO) {
        $stmt = $db->query("SHOW TABLES");
        if ($stmt) {
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
}

// Get table data if table is selected
$tableData = [];
$totalRecords = 0;
$columns = [];
$currentDatabase = '';

if ($db && $currentTable) {
    // Get current database
    if ($db instanceof mysqli) {
        $result = $db->query("SELECT DATABASE()");
        if ($result) {
            $row = $result->fetch_array();
            $currentDatabase = $row[0] ?? '';
            $result->free();
        }
        
        // Get columns
        $result = $db->query("SHOW COLUMNS FROM `" . $db->real_escape_string($currentTable) . "`");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row;
            }
            $result->free();
        }
        
        // Get total count
        $result = $db->query("SELECT COUNT(*) as total FROM `" . $db->real_escape_string($currentTable) . "`");
        if ($result) {
            $row = $result->fetch_assoc();
            $totalRecords = intval($row['total']);
            $result->free();
        }
        
        // Get data
        $query = "SELECT * FROM `" . $db->real_escape_string($currentTable) . "` LIMIT $perPage OFFSET $offset";
        $result = $db->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $tableData[] = $row;
            }
            $result->free();
        }
    } elseif ($db instanceof PDO) {
        // Get current database
        $stmt = $db->query("SELECT DATABASE()");
        if ($stmt) {
            $currentDatabase = $stmt->fetchColumn();
        }
        
        // Get columns
        $stmt = $db->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $currentTable) . "`");
        if ($stmt) {
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get total count
        $stmt = $db->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $currentTable) . "`");
        if ($stmt) {
            $totalRecords = intval($stmt->fetchColumn());
        }
        
        // Get data
        $query = "SELECT * FROM `" . str_replace('`', '``', $currentTable) . "` LIMIT $perPage OFFSET $offset";
        $stmt = $db->query($query);
        if ($stmt) {
            $tableData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$totalPages = $totalRecords > 0 ? ceil($totalRecords / $perPage) : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Viewer - NXT Panel</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .header .db-info {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .container {
            display: flex;
            height: calc(100vh - 100px);
        }
        
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid #e0e0e0;
            overflow-y: auto;
            box-shadow: 2px 0 5px rgba(0,0,0,0.05);
        }
        
        .sidebar-header {
            padding: 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
            color: #495057;
        }
        
        .table-list {
            list-style: none;
        }
        
        .table-item {
            border-bottom: 1px solid #f0f0f0;
        }
        
        .table-item a {
            display: block;
            padding: 12px 15px;
            color: #495057;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .table-item a:hover {
            background: #f8f9fa;
            color: #667eea;
        }
        
        .table-item.active a {
            background: #667eea;
            color: white;
            font-weight: 500;
        }
        
        .main-content {
            flex: 1;
            overflow-y: auto;
            background: white;
            padding: 20px;
        }
        
        .content-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .content-header h2 {
            color: #495057;
            margin-bottom: 5px;
        }
        
        .table-info {
            color: #6c757d;
            font-size: 14px;
        }
        
        .error-box {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 20px;
            border: 1px solid #f5c6cb;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-radius: 5px;
            overflow: hidden;
        }
        
        .data-table thead {
            background: #f8f9fa;
        }
        
        .data-table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }
        
        .data-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .data-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .pagination {
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .pagination-info {
            color: #6c757d;
            font-size: 14px;
        }
        
        .pagination-controls {
            display: flex;
            gap: 5px;
        }
        
        .pagination-btn {
            padding: 8px 12px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            color: #495057;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        .pagination-btn:hover:not(.disabled) {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .cell-content {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .cell-content:hover {
            white-space: normal;
            word-break: break-all;
        }
        
        .no-tables {
            padding: 40px 20px;
            text-align: center;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🗄️ Database Viewer</h1>
        <div class="db-info">
            <?php if ($db): ?>
                <?php if ($db instanceof mysqli): ?>
                    Connected to: <?php echo htmlspecialchars($db->host_info); ?>
                <?php elseif ($db instanceof PDO): ?>
                    Connected via PDO
                <?php endif; ?>
                <?php if ($currentDatabase): ?>
                    | Database: <strong><?php echo htmlspecialchars($currentDatabase); ?></strong>
                <?php endif; ?>
            <?php else: ?>
                <span style="color: #ffc107;">⚠️ Not Connected</span>
            <?php endif; ?>
        </div>
    </div>
    
            <?php if ($connectionError): ?>
        <div class="error-box">
            <strong>Connection Error:</strong> <?php echo htmlspecialchars($connectionError); ?>
            <br><br>
            <strong>Note:</strong> The database credentials are encrypted in <code>/home/nxt/config</code> file.
            <br><br>
            <strong>To fix this, you have two options:</strong>
            <ol style="margin-left: 20px; margin-top: 10px;">
                <li><strong>Option 1 - Manual Configuration:</strong>
                    <ul>
                        <li>Edit <code>/home/nxt/public/dbviewer_config.php</code></li>
                        <li>Set <code>auto_detect</code> to <code>false</code></li>
                        <li>Enter your database connection details (host, port, username, password)</li>
                        <li>Save and refresh this page</li>
                    </ul>
                </li>
                <li><strong>Option 2 - Decrypt Config File:</strong>
                    <ul>
                        <li>The config file at <code>/home/nxt/config</code> contains encrypted credentials</li>
                        <li>You need to decrypt it using the NXT Panel's decryption functions</li>
                        <li>The format appears to be: <code>S1:&lt;encrypted_data&gt;</code></li>
                        <li>Once decrypted, you can extract the credentials and use Option 1</li>
                    </ul>
                </li>
            </ol>
            <br>
            <strong>Config file info:</strong>
            <?php if (file_exists('/home/nxt/config')): ?>
                <br>File exists: Yes
                <br>Size: <?php echo filesize('/home/nxt/config'); ?> bytes
                <br>Format: <?php echo substr(file_get_contents('/home/nxt/config'), 0, 10); ?>...
            <?php else: ?>
                <br>File does not exist
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="container">
        <div class="sidebar">
            <?php if (!empty($databases) && count($databases) > 1): ?>
                <div class="sidebar-header" style="background: #e3f2fd;">
                    🗄️ Database
                    <select onchange="window.location='?db='+this.value" style="width:100%; margin-top:5px; padding:5px;">
                        <?php foreach ($databases as $dbName): ?>
                            <option value="<?php echo htmlspecialchars($dbName); ?>" <?php echo (($_GET['db'] ?? $databases[0]) === $dbName) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dbName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="sidebar-header">
                📋 Tables (<?php echo count($tables); ?>)
            </div>
            <?php if (empty($tables)): ?>
                <div class="no-tables">No tables found</div>
            <?php else: ?>
                <ul class="table-list">
                    <?php foreach ($tables as $table): ?>
                        <li class="table-item <?php echo $table === $currentTable ? 'active' : ''; ?>">
                            <a href="?table=<?php echo urlencode($table); ?>">
                                📊 <?php echo htmlspecialchars($table); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <div class="main-content">
            <?php if (!$currentTable): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">👈</div>
                    <h3>Select a table from the left menu</h3>
                    <p>Choose a table to view its records</p>
                </div>
            <?php elseif (empty($tableData) && $totalRecords === 0): ?>
                <div class="content-header">
                    <h2>📊 <?php echo htmlspecialchars($currentTable); ?></h2>
                    <div class="table-info">Table is empty</div>
                </div>
            <?php else: ?>
                <div class="content-header">
                    <h2>📊 <?php echo htmlspecialchars($currentTable); ?></h2>
                    <div class="table-info">
                        Showing <?php echo number_format($offset + 1); ?> - <?php echo number_format(min($offset + $perPage, $totalRecords)); ?> 
                        of <?php echo number_format($totalRecords); ?> records
                    </div>
                </div>
                
                <?php if (!empty($columns)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $col): ?>
                                    <th><?php echo htmlspecialchars($col['Field']); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tableData as $row): ?>
                                <tr>
                                    <?php foreach ($columns as $col): ?>
                                        <td>
                                            <div class="cell-content" title="<?php echo htmlspecialchars($row[$col['Field']] ?? ''); ?>">
                                                <?php 
                                                $value = $row[$col['Field']] ?? '';
                                                if ($value === null) {
                                                    echo '<em style="color: #999;">NULL</em>';
                                                } elseif ($value === '') {
                                                    echo '<em style="color: #999;">(empty)</em>';
                                                } else {
                                                    echo htmlspecialchars($value);
                                                }
                                                ?>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <div class="pagination-info">
                                Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?>
                            </div>
                            <div class="pagination-controls">
                                <a href="?table=<?php echo urlencode($currentTable); ?>&page=1" 
                                   class="pagination-btn <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    « First
                                </a>
                                <a href="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $currentPage - 1; ?>" 
                                   class="pagination-btn <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    ‹ Prev
                                </a>
                                
                                <?php
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                                
                                if ($startPage > 1): ?>
                                    <span class="pagination-btn disabled">...</span>
                                <?php endif; ?>
                                
                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <a href="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $i; ?>" 
                                       class="pagination-btn <?php echo $i === $currentPage ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($endPage < $totalPages): ?>
                                    <span class="pagination-btn disabled">...</span>
                                <?php endif; ?>
                                
                                <a href="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $currentPage + 1; ?>" 
                                   class="pagination-btn <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    Next ›
                                </a>
                                <a href="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $totalPages; ?>" 
                                   class="pagination-btn <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    Last »
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
