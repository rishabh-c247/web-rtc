<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$route['default_controller'] = 'auth';
$route['404_override']       = '';
$route['translate_uri_dashes'] = FALSE;

// Auth
$route['auth']        = 'auth/index';
$route['auth/login']  = 'auth/login';
$route['auth/logout'] = 'auth/logout';

// Dashboard
$route['dashboard'] = 'dashboard/index';

// API — Users
$route['api/users'] = 'api/users';

// API — Conversations
$route['api/conversations']        = 'api/conversations';
$route['api/conversations/create'] = 'api/conversations_create';

// API — Voice messages
$route['api/voice/mark_played'] = 'api/voice_mark_played';
$route['api/voice/(:num)']      = 'api/voice/$1';

// API — Transmissions
$route['api/transmission/(:any)'] = 'api/transmission/$1';

// API — Signaling
$route['api/signaling/(:any)'] = 'api/signaling/$1';

// API — Heartbeat (online presence)
$route['api/heartbeat'] = 'api/heartbeat';
$route['api/offline']   = 'api/offline';

// CLI-only migration runner (web access returns 404)
$route['migrate']          = 'migrate/index';
$route['migrate/rollback'] = 'migrate/rollback';

// Serve uploaded voice files through CI (access control)
$route['voice-file/(:any)'] = 'api/serve_voice/$1';
