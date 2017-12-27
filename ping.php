<?php
    date_default_timezone_set('UTC');

    include('./config.php');

    ini_set('display_errors', DEBUG);
    error_reporting(DEBUG ? E_ALL : 0);

    header('Content-type: text/plain');

    // === functions ===

    function check_port($ip, $port)
    {
        return @fsockopen($ip, $port, $errno, $errstr, PORT_CHECK_TIMEOUT);
    }

    function insert_columns_sql($columns)
    {
        return "('" . implode("', '", array_keys($columns)) . "') VALUES (:" . implode(', :', array_keys($columns)) . ")";
    }

    function update_columns_sql($columns)
    {
        return "SET " . implode(', ', array_map(function($k) { return "'".$k."' = :".$k; }, array_keys($columns)));
    }

    function bind_columns($query, $columns, $data)
    {
        foreach ($columns as $column => $type)
        {
            // Force some basic type safety
            $value = $data[$column];
            switch ($type)
            {
                case PDO::PARAM_INT: $value = intval($value); break;
                case PDO::PARAM_BOOL: $value = intval(filter_var($value, FILTER_VALIDATE_BOOLEAN)); break;
                case PDO::PARAM_STR: $value = htmlspecialchars($value); break;
            }

            $query->bindValue(':'.$column, $value, $type);
        }
    }

    function update_db_info($gameinfo)
    {
        $db = new PDO(DATABASE);

        $server_columns = array(
            'name' => PDO::PARAM_STR,
            'address' => PDO::PARAM_STR,
            'ts' => PDO::PARAM_INT,
            'state' => PDO::PARAM_INT,
            'map' => PDO::PARAM_STR,
            'mod' => PDO::PARAM_STR,
            'version' => PDO::PARAM_STR,
            'protected' => PDO::PARAM_BOOL,
            'players' => PDO::PARAM_INT,
            'bots' => PDO::PARAM_INT,
            'spectators' => PDO::PARAM_INT,
            'maxplayers' => PDO::PARAM_INT,
            'started' => PDO::PARAM_STR,
        );

        // Bump the protocol version whenever columns are added
        $started_protocol = 2;
        $started_columns = array(
            'game_id' => PDO::PARAM_INT,
            'protocol' => PDO::PARAM_INT,
            'name' => PDO::PARAM_STR,
            'address' => PDO::PARAM_STR,
            'map' => PDO::PARAM_STR,
            'mod' => PDO::PARAM_STR,
            'version' => PDO::PARAM_STR,
            'protected' => PDO::PARAM_BOOL,
            'players' => PDO::PARAM_INT,
            'bots' => PDO::PARAM_INT,
            'spectators' => PDO::PARAM_INT,
            'maxplayers' => PDO::PARAM_INT,
            'started' => PDO::PARAM_STR,
        );

        $client_columns = array(
            'address' => PDO::PARAM_STR,
            'name' => PDO::PARAM_STR,
            'color' => PDO::PARAM_STR,
            'faction' => PDO::PARAM_STR,
            'team' => PDO::PARAM_INT,
            'spawnpoint' => PDO::PARAM_INT,
            'isadmin' => PDO::PARAM_BOOL,
            'isspectator' => PDO::PARAM_BOOL,
            'isbot' => PDO::PARAM_BOOL,
            'ts' => PDO::PARAM_INT,
        );

        // Check the last state of the server
        $query_state = $db->prepare('SELECT id, state, started FROM servers WHERE address = :address');
        $query_state->bindValue(':address', $gameinfo['address'], PDO::PARAM_STR);
        $query_state->execute();
        if ($row = $query_state->fetch())
        {
            $gameinfo['id'] = $row['id'];
            $gameinfo['last_state'] = $row['state'];
            $gameinfo['started'] = $row['started'];
        }

        // Update latest server metadata
        $update_server = $db->prepare("UPDATE servers " . update_columns_sql($server_columns) . " WHERE address = :address");
        bind_columns($update_server, $server_columns, $gameinfo);
        $update_server->execute();
        if (!$update_server->rowCount())
        {
            $update_server = $db->prepare("INSERT INTO servers " . insert_columns_sql($server_columns));
            bind_columns($update_server, $server_columns, $gameinfo);
            $update_server->execute();
        }

        // Update latest client metadata
        $delete_clients = $db->prepare('DELETE FROM clients WHERE address = :address');
        $delete_clients->bindValue(':address', $gameinfo['address'], PDO::PARAM_STR);
        $delete_clients->execute();

        foreach ($gameinfo['clients'] as $client)
        {
            $insert_client = $db->prepare("INSERT INTO clients " . insert_columns_sql($client_columns));
            $client_data = array_merge($client, array(
                'address' => $gameinfo['address'],
                'ts' => $gameinfo['ts']
            ));

            bind_columns($insert_client, $client_columns, $client_data);
            $insert_client->execute();
        }

        // Game has just started
        if (array_key_exists('last_state', $gameinfo) && $gameinfo['last_state'] == 1 && $gameinfo['state'] == 2)
        {
            // Set the started field in the servers table
            $set_started = $db->prepare("UPDATE OR FAIL `servers` SET 'started' = :started WHERE address = :address");
            $gameinfo['started'] = date('Y-m-d H:i:s');
            $set_started->bindValue(':started', $gameinfo['started'], PDO::PARAM_STR);
            $set_started->bindValue(':address', $gameinfo['address'], PDO::PARAM_STR);
            $set_started->execute();

            if (DEBUG)
                $set_started->debugDumpParams();

            // Copy server record to the started table
            // This freezes the state (mainly the player count) at the time that the game started
            $copy_started = $db->prepare("INSERT INTO started " . insert_columns_sql($started_columns));

            // Copy all the game info except for 'id', which maps to 'game_id'
            $started_data = $gameinfo;
            unset($started_data['id']);
            $started_data['game_id'] = $gameinfo['id'];
            $started_data['protocol'] = $started_protocol;

            bind_columns($copy_started, $started_columns, $started_data);
            $copy_started->execute();
        }

        // Game has just finished
        else if ($gameinfo['state'] == 3)
        {
            // Game actually started
            if (array_key_exists('last_state', $gameinfo) && array_key_exists('id', $gameinfo) && $gameinfo['last_state'] == 2)
            {
                // Update map stats
                $update_map_plays = $db->prepare('UPDATE map_stats SET played_counter = played_counter + 1 WHERE map = :map');
                $update_map_plays->bindValue(':map', $gameinfo['map'], PDO::PARAM_STR);
                $update_map_plays->execute();
                if (!$update_map_plays->rowCount())
                {
                    $update_map_plays = $db->prepare("INSERT INTO map_stats ('map', 'played_counter', 'last_change')
                        VALUES (:map, 1, :last_change)");
                    $update_map_plays->bindValue(':map', $gameinfo['map'], PDO::PARAM_STR);
                    $update_map_plays->bindValue(':last_change', date('Y-m-d H:i:s'), PDO::PARAM_STR);
                    $update_map_plays->execute();
                }

                if (DEBUG)
                    $update_map_plays->debugDumpParams();

                // Record the finish time on the started table
                $set_finished = $db->prepare("UPDATE OR FAIL started SET 'finished' = :finished WHERE game_id = :game_id");
                $gameinfo['finished'] = date('Y-m-d H:i:s');
                $set_finished->bindValue(':finished', date('Y-m-d H:i:s'), PDO::PARAM_STR);
                $set_finished->bindValue(':game_id', $gameinfo['id'], PDO::PARAM_STR);
                $set_finished->execute();

                if (DEBUG)
                    $set_finished->debugDumpParams();
            }

            $remove = $db->prepare('DELETE FROM servers WHERE address = :addr');
            $remove->bindValue(':addr', $gameinfo['address'], PDO::PARAM_STR);
            $remove->execute();

            $stale_ts = time() - STALE_GAME_TIMEOUT;
            $remove = $db->prepare('DELETE FROM servers WHERE ts < :stale');
            $remove->bindValue(':stale', $stale_ts, PDO::PARAM_INT);
            $remove->execute();

            $remove = $db->prepare('DELETE FROM clients WHERE address = :addr OR ts < :stale');
            $remove->bindValue(':addr', $gameinfo['address'], PDO::PARAM_STR);
            $remove->bindValue(':stale', $stale_ts, PDO::PARAM_INT);
            $remove->execute();
        }

        unset($db);
        return true;
    }

    function parse_ping($data)
    {
        $client_copy_fields = array(
            'Name' => 'name',
            'Color' => 'color',
            'Faction' => 'faction',
            'Team' => 'team',
            'SpawnPoint' => 'spawnpoint',
            'IsAdmin' => 'isadmin',
            'IsSpectator' => 'isspectator',
            'IsBot' => 'isbot',
        );

        $game_copy_fields = array(
            'Name' => 'name',
            'Mod' => 'mod',
            'Version' => 'version',
            'Map' => 'map',
            'State' => 'state',
            'MaxPlayers' => 'maxplayers',
            'Protected' => 'protected',
        );

        $lines = explode("\n", $data);
        if (trim(array_shift($lines)) != "Game:")
            return false;

        // Turn data into an array of (key, value, indent)
        $statements = array();
        foreach ($lines as $line)
        {
            // Ignore completely empty lines
            if (!$line)
                continue;

            // All lines in the posted data must be key: value format
            $split = strpos($line, ":");
            if ($split === false)
                return false;

            // Lines must be indented with zero or more tabs (not spaces)
            $indent = 0;
            while ($indent < strlen($line) && $line[$indent] == "\t")
                $indent++;

            if ($indent >= $split)
                return false;

            $statements[] = array(
                'indent' => $indent,
                'key' => substr($line, $indent, $split - $indent),
                'value' => trim(substr($line, $split + 1))
            );
        }

        // Parse the statements into a bundle of game info
        $gameinfo = array(
            'ts' => time(),
            'clients' => array(),
            'spectators' => 0,
            'bots' => 0,
        );

        $client = -1;
        $parse_clients = false;
        foreach ($statements as $statement)
        {
            if ($parse_clients)
            {
                // New client block
                if ($statement['indent'] == 2 && preg_match('/Client@\d+/', $statement['key']))
                {
                    $gameinfo['clients'][] = array();
                    $client += 1;
                }

                // Client data
                else if ($statement['indent'] == 3)
                {
                    // Copy over simple values
                    if (array_key_exists($statement['key'], $client_copy_fields))
                        $gameinfo['clients'][$client][$client_copy_fields[$statement['key']]] = $statement['value'];

                    // Some client fields require extra logic
                    switch ($statement['key'])
                    {
                        case 'IsSpectator':
                            if (filter_var($statement['value'], FILTER_VALIDATE_BOOLEAN))
                                $gameinfo['spectators'] += 1;
                            break;
                        case 'IsBot':
                            if (filter_var($statement['value'], FILTER_VALIDATE_BOOLEAN))
                                $gameinfo['bots'] += 1;
                            break;
                    }
                }

                // Invalid syntax
                else
                    return false;

                continue;
            }

            // All non-client nodes must have a single level of indentation
            if ($statement['indent'] != 1)
                return false;

            // Copy over simple values
            if (array_key_exists($statement['key'], $game_copy_fields))
                $gameinfo[$game_copy_fields[$statement['key']]] = $statement['value'];

            // Some fields require extra logic
            switch ($statement['key'])
            {
                case 'Address':
                    $gameinfo['port'] = array_pop(explode(':', $statement['value']));
                    break;
                case 'Clients':
                    $parse_clients = true;
                    break;
            }
        }

        // Check that we got data for all the expected fields
        if (sizeof($gameinfo) != 12)
            return false;

        foreach ($gameinfo['clients'] as $client)
            if (sizeof($client) != 8)
                return false;

        $gameinfo['players'] = sizeof($gameinfo['clients']) - $gameinfo['spectators'] - $gameinfo['bots'];
        return $gameinfo;
    }

    function parse_legacy_ping()
    {
        // Make sure everything we need is actually set.
        foreach (array('port', 'name', 'state', 'map', 'mods', 'players') as $key)
            if (!isset($_REQUEST[$key]))
                return false;

        $version_arr = explode('@', $_REQUEST['mods']);
        $mod = array_shift($version_arr);
        $mod_version = implode('@', $version_arr);

        return array(
            'name'      => urldecode($_REQUEST['name']),
            'port'      => $_REQUEST['port'],
            'players'   => $_REQUEST['players'],
            'state'     => $_REQUEST['state'],
            'ts'        => time(),
            'map'       => $_REQUEST['map'],
            'bots'      => isset($_REQUEST['bots']) ? $_REQUEST['bots'] : 0,
            'spectators'=> isset($_REQUEST['spectators']) ? $_REQUEST['spectators'] : 0,
            'maxplayers'=> isset($_REQUEST['maxplayers']) ? $_REQUEST['maxplayers'] : 0,
            'protected' => isset($_REQUEST['protected']) ? $_REQUEST['protected'] : 0,
            'clients'   => array(),
            'mod'       => $mod,
            'version'   => $mod_version,
        );
    }

    // === body ===

    try
    {
        $postdata = file_get_contents('php://input');
        $gameinfo = $postdata ? parse_ping($postdata) : parse_legacy_ping();
        if (!$gameinfo)
            die('[003] Advertisement data is not in the expected format');

        $port = intval($gameinfo['port']);
        $gameinfo['address'] = $_SERVER['REMOTE_ADDR'].':'.$port;
        if ($gameinfo['state'] == 1 && !check_port($_SERVER['REMOTE_ADDR'], $port))
            die('[001] game server "'.$gameinfo['address'].'" does not respond');

        update_db_info($gameinfo);
    }
    catch (Exception $e)
    {
        die('[004] Failed to update server database');
        error_log($e->getMessage());
    }
?>
