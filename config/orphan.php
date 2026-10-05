<?php

return [
    'xboard_update_mode' => env('XBOARD_UPDATE_MODE', 'git'),
    'xboard_update_base_url' => env('XBOARD_UPDATE_BASE_URL', ''),
    'xboard_release_dir' => env('XBOARD_RELEASE_DIR', storage_path('app/private/xboard-releases')),
    'xboard_repository' => env('XBOARD_UPDATE_REPOSITORY', 'ksr-v/Xboard-Custom-Source'),
    'xboard_ref' => env('XBOARD_UPDATE_REF', 'master'),
    'xboard_update_token' => env('XBOARD_UPDATE_TOKEN', ''),
    'xboard_build_commit' => env('XBOARD_BUILD_COMMIT', ''),
    'node_installer_dir' => env('XBOARD_NODE_INSTALLER_DIR', storage_path('app/private/node-installer')),
];