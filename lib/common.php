<?php
function p_common_run($remote_cmd) {
    p_remote_run($remote_cmd);
}

function p_common_sync() {
    $args = func_get_args();
    $from = array_shift($args);
    $to = array_shift($args);
    $options = _p_read_options($args);
    if (!empty ($options['exclude'])) {
        $options['exclude'] = array_map('trim', array_filter(explode(',', $options['exclude'])));
    }
    p_sync($from, $to, $options);
}

function p_remote_run($remote_cmd, $server = null) {
    if ($server === null) {
        foreach (p_servers() as $server) {
            if ($server !== null) {
                p_remote_run($remote_cmd, $server);
            }
        }
        return;
    }
    
    p_log_indent("Running {$remote_cmd} on {$server['host']}");
    $cmd = array ('ssh');
    if (!empty ($server['port'])) {
        $cmd[] = "-p{$server['port']}";
    }
    if (empty ($server['ssh_key'])) {
        p_log("Skipping {$server['host']}: no SSH key");
    }
    $cmd[] = "-i " . p_local_path($server['ssh_key']);
    $conn = "";
    if (!empty ($server['user'])) {
        $conn .= "{$server['user']}@";
    }
    $conn .= $server['host'];
    $cmd[] = $conn;
    
    $cmd[] = escapeshellarg(p_apply_vars($remote_cmd, $server));
    
    $cmd = implode(' ', $cmd);
    p_shell_cmd($cmd, array (
        'stdout' => function ($line) use ($server) {
            if (!$line = trim($line)) {
                return;
            }
            p_output(p_log_server_id($server) . '> ' . $line . "\n");
        }, 
        'stderr' => function ($line) use ($server) {
            if (!$line = trim($line)) {
                return;
            }
            p_error(p_log_server_id($server) . '> ' . $line);
        }
    ));
    
    p_log_unindent();
}

/**
 * Sync files from the specified source directory to the specified target directory.
 * 
 * Options:
 * - delete: delete files that are in the target directory but not in the source? Defaults to false.
 * - exclude: path, or array of paths, to exclude
 * - cvs_exclude: exclude .svn, .git etc.? Defaults to true
 * 
 * @param string $src
 * @param string $dest
 * @param array $options
 */
function p_sync($src, $dest, array $options = array ()) {
    $options += array (
        'delete' => false,
        'exclude' => array (),
        'cvs_exclude' => true,
    );
    if ($options['exclude'] && !is_array($options['exclude'])) {
        $options['exclude'] = array ($options['exclude']);
    }
    
    $src_abs = p_local_path($src);
    
    foreach (p_servers() as $server) {
        p_log_indent("Syncing {$src} to {$server['host']}:{$dest}");
        
        $dest_abs = p_apply_vars($dest, $server);
        if (isset ($server['root_dir'])) {
            $dest_abs = _p_path_merge($server['root_dir'], $dest_abs);
        }
        $dest_abs = $server['host'] . ':' . $dest_abs;
        if (!empty ($server['user'])) {
            $dest_abs = "{$server['user']}@{$dest_abs}";
        }
        
        $args = array ('-avz');
        $ssh_cmd = 'ssh';
        if (!empty ($server['port'])) {
            $ssh_cmd .= " -p{$server['port']}";
        }
        if (!empty ($server['ssh_key'])) {
            $ssh_cmd .= " -i " . p_local_path($server['ssh_key']);
        }
        $args[] = '-e ' . escapeshellarg($ssh_cmd);
        if ($options['delete']) {
            $args[] = '--delete';
        }
        if ($options['cvs_exclude']) {
            $args[] = '--cvs-exclude';
        }
        if ($options['exclude']) {
            foreach (array_filter($options['exclude']) as $exclude) {
                $args[] = '--exclude=' . p_apply_vars($exclude, $server);
            }
        }
        
        $args[] = $src_abs . '/';
        $args[] = $dest_abs;
        
        p_shell_cmd('rsync ' . implode(' ', $args));
        
        p_log_unindent();
    }
}