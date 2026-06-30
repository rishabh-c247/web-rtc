<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Api controller — all AJAX endpoints for the voice transmission system.
 *
 * Every method returns JSON. All endpoints require an authenticated session
 * except `serve_voice` (which double-checks membership via DB).
 */
class Api extends CI_Controller {

    public function __construct()
    {
        parent::__construct();
        $this->load->model([
            'User_model',
            'Conversation_model',
            'Transmission_model',
            'Signaling_model',
            'Voice_message_model',
        ]);
    }

    // ----------------------------------------------------------------
    // Auth guard + helpers
    // ----------------------------------------------------------------

    private function _require_auth()
    {
        $user_id = $this->session->userdata('user_id');
        $token   = $this->session->userdata('session_token');

        if (!$user_id || !$token) {
            $this->_json(['error' => 'Unauthorized'], 401);
            exit;
        }

        if (!$this->User_model->validate_session_token($user_id, $token)) {
            $this->session->sess_destroy();
            $this->_json(['error' => 'Session expired', 'kicked' => true], 401);
            exit;
        }
    }

    private function _me()
    {
        return (int) $this->session->userdata('user_id');
    }

    private function _json($data, $status = 200)
    {
        $this->output
            ->set_status_header($status)
            ->set_content_type('application/json')
            ->set_output(json_encode($data));
    }

    /**
     * Decode JSON body sent via fetch(..., { body: JSON.stringify(...) }).
     */
    private function _body($key = null)
    {
        static $decoded = null;
        if ($decoded === null) {
            $raw     = file_get_contents('php://input');
            $decoded = json_decode($raw, true) ?? [];
        }
        if ($key !== null) {
            return $decoded[$key] ?? null;
        }
        return $decoded;
    }

    // ----------------------------------------------------------------
    // GET /api/users
    // Returns all users except the caller (for the "New conversation" selector).
    // ----------------------------------------------------------------
    public function users()
    {
        $this->_require_auth();
        $users = $this->User_model->get_other_users($this->_me());
        $this->_json(['users' => $users]);
    }

    // ----------------------------------------------------------------
    // GET /api/conversations
    // Returns all conversations the caller belongs to.
    // ----------------------------------------------------------------
    public function conversations()
    {
        $this->_require_auth();
        $this->User_model->mark_stale_offline();
        $list = $this->Conversation_model->get_user_conversations($this->_me());
        $this->_json(['conversations' => $list]);
    }

    // ----------------------------------------------------------------
    // POST /api/conversations/create
    // Find-or-create a 1:1 conversation with another user.
    // ----------------------------------------------------------------
    public function conversations_create()
    {
        $this->_require_auth();
        $other = (int) $this->_body('other_user_id');
        if (!$other) {
            $this->_json(['error' => 'other_user_id required'], 400);
            return;
        }
        if ($other === $this->_me()) {
            $this->_json(['error' => 'Cannot create conversation with yourself'], 400);
            return;
        }

        // Avoid CI HTML DB error pages for API requests; return JSON instead.
        $db_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        try {
            $existing = $this->Conversation_model->find_between($this->_me(), $other);
            $db_err = $this->db->error();
            if (!empty($db_err['code'])) {
                $this->_json([
                    'error' => 'Database error while checking existing conversation',
                    'details' => $db_err['message'],
                ], 500);
                return;
            }

            if ($existing) {
                $this->_json(['success' => true, 'conversation_id' => (int) $existing->id]);
                return;
            }

            $id = $this->Conversation_model->create($this->_me(), $other);
            $db_err = $this->db->error();
            if (!$id || !empty($db_err['code'])) {
                $this->_json([
                    'error' => 'Database error while creating conversation',
                    'details' => $db_err['message'] ?? 'Unknown DB error',
                ], 500);
                return;
            }

            $this->_json(['success' => true, 'conversation_id' => (int) $id]);
        } finally {
            $this->db->db_debug = $db_debug;
        }
    }

    // ----------------------------------------------------------------
    // GET /api/voice/{conv_id}
    // Returns all voice message cards for a conversation.
    // ----------------------------------------------------------------
    public function voice($conv_id = 0)
    {
        $this->_require_auth();
        $conv_id = (int) $conv_id;

        // Verify membership
        $conv = $this->Conversation_model->get_for_user($conv_id, $this->_me());
        if (!$conv) {
            $this->_json(['error' => 'Not found'], 404);
            return;
        }

        $messages = $this->Voice_message_model->get_for_conversation($conv_id, $this->_me());
        $this->_json(['messages' => $messages]);
    }

    // ----------------------------------------------------------------
    // POST /api/transmission/start
    // ----------------------------------------------------------------
    // POST /api/transmission/stop
    // ----------------------------------------------------------------
    // POST /api/transmission/upload   (multipart/form-data)
    // ----------------------------------------------------------------
    // POST /api/transmission/poll
    //   Receiver polls to detect an incoming live transmission.
    // ----------------------------------------------------------------
    public function transmission($action = '')
    {
        $this->_require_auth();
        $me = $this->_me();

        switch ($action) {

            // ---- start ----
            case 'start':
                $conv_id     = (int) $this->_body('conversation_id');
                $receiver_id = (int) $this->_body('receiver_id');

                if (!$conv_id || !$receiver_id) {
                    $this->_json(['error' => 'conversation_id and receiver_id required'], 400);
                    return;
                }

                // Guard: sender must not already be transmitting
                if ($this->Transmission_model->is_transmitting($me)) {
                    $this->_json(['success' => false, 'message' => 'You are already transmitting.']);
                    return;
                }

                // Determine live vs. pending
                $is_live = $this->Transmission_model->can_receive($receiver_id);

                $tx_id = $this->Transmission_model->create($conv_id, $me, $receiver_id, $is_live);

                // Pre-create a pending placeholder card for both live and pending modes.
                // This gives immediate UX feedback while audio upload is still in progress.
                $this->Voice_message_model->create([
                    'transmission_id' => $tx_id,
                    'conversation_id' => $conv_id,
                    'sender_id'       => $me,
                    'receiver_id'     => $receiver_id,
                    'is_pending'      => 1,
                ]);

                $this->_json([
                    'success'         => true,
                    'transmission_id' => $tx_id,
                    'is_live'         => (bool) $is_live,
                ]);
                break;

            // ---- stop ----
            case 'stop':
                $tx_id = (int) $this->_body('transmission_id');
                if (!$tx_id) {
                    $this->_json(['error' => 'transmission_id required'], 400);
                    return;
                }
                $tx = $this->Transmission_model->get($tx_id);
                if (!$tx || (int) $tx->sender_id !== $me) {
                    $this->_json(['error' => 'Forbidden'], 403);
                    return;
                }
                $this->Transmission_model->complete($tx_id);
                // Send hang_up signal to receiver (live transmissions only)
                if ((int) $tx->is_live === 1) {
                    $this->Signaling_model->save([
                        'transmission_id' => $tx_id,
                        'from_user_id'    => $me,
                        'to_user_id'      => (int) $tx->receiver_id,
                        'message_type'    => 'hang_up',
                        'payload'         => '{}',
                    ]);
                }
                $this->_json(['success' => true]);
                break;

            // ---- upload ----
            case 'upload':
                $tx_id    = (int) $this->input->post('transmission_id');
                $duration = (int) $this->input->post('duration');

                if (!$tx_id) {
                    $this->_json(['error' => 'transmission_id required'], 400);
                    return;
                }
                $tx = $this->Transmission_model->get($tx_id);
                if (!$tx || (int) $tx->sender_id !== $me) {
                    $this->_json(['error' => 'Forbidden'], 403);
                    return;
                }

                $upload_path = FCPATH . 'uploads/voice/';
                if (!is_dir($upload_path)) {
                    mkdir($upload_path, 0777, true);
                }

                $config = [
                    'upload_path'   => $upload_path,
                    'allowed_types' => 'webm|ogg|mp3|wav|opus',
                    'max_size'      => 51200,
                    'file_name'     => 'voice_' . $tx_id . '_' . time(),
                    'overwrite'     => false,
                ];
                $this->load->library('upload', $config);

                if (!$this->upload->do_upload('audio')) {
                    $this->_json(['error' => $this->upload->display_errors('', '')], 422);
                    return;
                }

                $file_data = $this->upload->data();
                $rel_path  = 'uploads/voice/' . $file_data['file_name'];

                $this->Transmission_model->set_audio($tx_id, $rel_path);

                // Finalise the pending placeholder card.
                $pending = $this->Voice_message_model->get_by_transmission($tx_id);
                if ($pending) {
                    $this->Voice_message_model->finalize($tx_id, $rel_path, $duration);
                } else {
                    // Backward-compatible fallback if a placeholder was not created.
                    $this->Voice_message_model->create([
                        'transmission_id' => $tx_id,
                        'conversation_id' => (int) $tx->conversation_id,
                        'sender_id'       => (int) $tx->sender_id,
                        'receiver_id'     => (int) $tx->receiver_id,
                        'audio_file_path' => $rel_path,
                        'duration'        => $duration,
                        'is_pending'      => 0,
                    ]);
                }

                $this->_json(['success' => true, 'file_path' => $rel_path]);
                break;

            // ---- poll (receiver checks for incoming live transmission) ----
            case 'poll':
                $this->User_model->mark_stale_offline();
                $incoming = $this->Transmission_model->get_incoming_for_receiver($me);
                $pending  = $this->Transmission_model->get_pending_for_receiver($me);
                $this->_json([
                    'incoming' => $incoming,
                    'pending'  => $pending,
                ]);
                break;

            default:
                $this->_json(['error' => 'Unknown action'], 404);
        }
    }

    // ----------------------------------------------------------------
    // POST /api/signaling/send
    // Stores a WebRTC signaling message (offer / answer / ICE / hang_up).
    // ----------------------------------------------------------------
    // POST /api/signaling/poll
    // Returns and consumes pending signaling messages for the caller.
    // ----------------------------------------------------------------
    public function signaling($action = '')
    {
        $this->_require_auth();
        $me = $this->_me();

        switch ($action) {

            case 'send':
                $tx_id = (int) $this->_body('transmission_id');
                $type  = $this->_body('message_type');
                $payload = $this->_body('payload');

                if (!$tx_id || !$type) {
                    $this->_json(['error' => 'transmission_id and message_type required'], 400);
                    return;
                }
                $tx = $this->Transmission_model->get($tx_id);
                if (!$tx) {
                    $this->_json(['error' => 'Transmission not found'], 404);
                    return;
                }

                // Route to the other party
                $to = ((int) $tx->sender_id === $me) ? (int) $tx->receiver_id : (int) $tx->sender_id;

                $this->Signaling_model->save([
                    'transmission_id' => $tx_id,
                    'from_user_id'    => $me,
                    'to_user_id'      => $to,
                    'message_type'    => $type,
                    'payload'         => is_string($payload) ? $payload : json_encode($payload),
                ]);
                $this->_json(['success' => true]);
                break;

            case 'poll':
                $tx_id = (int) $this->_body('transmission_id');
                if (!$tx_id) {
                    $this->_json(['messages' => []]);
                    return;
                }
                $messages = $this->Signaling_model->consume($tx_id, $me);
                $this->_json(['messages' => $messages]);
                break;

            default:
                $this->_json(['error' => 'Unknown action'], 404);
        }
    }

    // ----------------------------------------------------------------
    // POST /api/voice/mark_played
    // ----------------------------------------------------------------
    public function voice_mark_played()
    {
        $this->_require_auth();
        $id = (int) $this->_body('id');
        if ($id) {
            $this->Voice_message_model->mark_played($id);
        }
        $this->_json(['ok' => true]);
    }

    // ----------------------------------------------------------------
    // POST /api/heartbeat
    // Client pings every ~1 s while the tab is active to keep is_online = 1.
    // ----------------------------------------------------------------
    public function heartbeat()
    {
        $this->_require_auth();
        $this->User_model->heartbeat($this->_me());
        $this->_json(['ok' => true]);
    }

    // ----------------------------------------------------------------
    // POST /api/offline
    // Immediate offline signal (tab close, hide, or network loss).
    // ----------------------------------------------------------------
    public function offline()
    {
        $this->_require_auth();
        $this->User_model->go_offline($this->_me());
        $this->_json(['ok' => true]);
    }

    // ----------------------------------------------------------------
    // GET /voice-file/{filename}
    // Serve audio file with session check.
    // ----------------------------------------------------------------
    public function serve_voice($filename = '')
    {
        $this->_require_auth();

        $filename = basename($filename);
        $path     = FCPATH . 'uploads/voice/' . $filename;

        if (!file_exists($path)) {
            show_404();
            return;
        }

        // Determine MIME
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'webm'  => 'audio/webm',
            'ogg'   => 'audio/ogg',
            'opus'  => 'audio/ogg; codecs=opus',
            'mp3'   => 'audio/mpeg',
            'wav'   => 'audio/wav',
            default => 'application/octet-stream',
        };
        $size = filesize($path);
        $start = 0;
        $end   = $size - 1;

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');

        // Proper byte-range handling for HTML audio seeking/streaming.
        $range = $this->input->server('HTTP_RANGE');
        if ($range && preg_match('/bytes=(\d*)-(\d*)/i', $range, $matches)) {
            if ($matches[1] !== '') {
                $start = (int) $matches[1];
            }
            if ($matches[2] !== '') {
                $end = (int) $matches[2];
            }
            if ($start > $end || $start >= $size) {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                exit;
            }
            if ($end >= $size) {
                $end = $size - 1;
            }

            $length = $end - $start + 1;
            header('HTTP/1.1 206 Partial Content');
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            header('Content-Length: ' . $length);

            $fp = fopen($path, 'rb');
            if ($fp === false) {
                show_404();
                return;
            }
            fseek($fp, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($fp)) {
                $read = min(8192, $remaining);
                $chunk = fread($fp, $read);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($fp);
            exit;
        }

        header('Content-Length: ' . $size);
        readfile($path);
        exit;
    }
}
