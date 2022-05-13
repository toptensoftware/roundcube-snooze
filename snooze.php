<?php

/**
 * Snooze
 * Copyright (C) Topten Software
 * 
 * Put your emails to sleep.
 *
 * @version 1.0
 * @license GNU GPLv3+
 * @author Brad Robinson
 */
class snooze extends rcube_plugin
{
    public $task = 'settings|mail';
    private $snooze_folder;

    // Initialize plugin
    function init()
    {
        $rcmail = rcmail::get_instance();

        // Load config, get snooze folder, quit if not set
        $this->load_config();
        $this->snooze_folder = $rcmail->config->get('snooze_mbox');
        if (!$this->snooze_folder)
            return;

        // Mail task?
        if ($rcmail->task == 'mail') 
        {
            // Strings
            $this->add_texts('localization', true);

            // Setup to request the snooze headers when fetching emails
            $this->add_hook('storage_init', [$this, 'on_storage_init']);

            // Handler for snooze action request
            $this->register_action('plugin.snooze_messages', [$this, 'on_snooze_messages']);

            // Styles
            $this->include_stylesheet($this->local_skin_path() . '/snooze.css');

            // Hook to show snooze status in message header area
            if ($rcmail->action == 'show' || $rcmail->action == 'preview') 
            {
                $this->add_hook('template_object_messageheaders', [$this, 'on_template_object_messageheaders']);
                $this->add_hook('message_load', [$this, 'on_message_load']);
            }

            // Add the snooze button
            if ($rcmail->action == '' || $rcmail->action == 'show') 
            {
                $this->include_script('snooze.js');
                $this->add_button(
                    [
                        'id'       => 'snoozemenulink',
                        'type'     => 'link',
                        'label'    => 'buttontext',
                        'href'     => '#',
                        'class'    => 'button buttonPas snooze disabled',
                        'classact' => 'button snooze',
                        'width'    => 32,
                        'height'   => 32,
                        'title'    => 'buttontitle',
                        'domain'   => $this->ID,
                        'innerclass' => 'inner',
                        'aria-owns'     => 'snoozemenu',
                        'aria-haspopup' => 'true',
                        'aria-expanded' => 'false',
                        'data-popup' => 'snoozemenu',
                    ],
                    'toolbar');

                // Register hook to localize the snooze folder
                $this->add_hook('render_mailboxlist', [$this, 'on_render_mailboxlist']);

                // Send environment variables for client
                $rcmail->output->set_env('snooze_folder', $this->snooze_folder);
                $rcmail->output->set_env('snooze_time_format', $rcmail->config->get('time_format'));
                $rcmail->output->set_env('snooze_time_zone', $rcmail->config->get('timezone'));
                $rcmail->output->set_env('snooze_morning', $rcmail->config->get('snooze_morning'));
                $rcmail->output->set_env('snooze_evening', $rcmail->config->get('snooze_evening'));

                // Add other GUI bits
                $rcmail->output->add_footer($this->create_snooze_menu());
                $rcmail->output->add_footer($this->create_pickatime_dialog());
            }
        }

        // Add settings
        if ($rcmail->task == "settings")
        {
            $this->add_hook('preferences_sections_list', [$this, 'on_preferences_sections_list']);
            $this->add_hook('preferences_list', [$this, 'on_preferences_list']);
            $this->add_hook('preferences_save', [$this, 'on_preferences_save']);
            $this->include_stylesheet($this->local_skin_path() . '/snooze.css');
        }
    }

    // Storage initializer callback
    function on_storage_init($p)
    {
        // When fetching mail headers, also get the snooze header
        // so we can display snooze info on emails
        $p['fetch_headers'] = $p['fetch_headers'] . ' X-Snoozed';
        return $p;
    }

    // Create the snooze menu
    private function create_snooze_menu()
    {
        $menu    = [];
        $rcmail = rcmail::get_instance();

        $ul_attr = ['role' => 'menu', 'aria-labelledby' => 'aria-label-snoozemenu'];

        if ($rcmail->config->get('skin') != 'classic') {
            $ul_attr['class'] = 'menu listing';
        }

        $intervals = [ 
            'latertoday', 'tomorrow', 'laterthisweek', 'thisweekend',
            'nextweek', 'nextweekend', 'pickatime', 'unsnooze'
        ];

        foreach ($intervals as $type) {
            $menu[] = html::tag('li', null, $rcmail->output->button([
                    'command'  => "snooze.$type",
                    'label'    => "snooze.$type",
                    'class'    => "snooze $type disabled",
                    'classact' => "snooze $type active",
                    'type'     => 'link',
                    'id'       => "snooze-$type",
                ])
            );
        }

        return html::div([
            'id' => 'snoozemenu', 
            'class' => 'popupmenu', 
            'aria-hidden' => 'true',
            'data-popup-init' => 'snoozemenu_init',
        ],
                html::tag('h3', ['id' => 'aria-label-snoozemenu'], $this->gettext('snoozeUntil'))
                . html::tag('ul', $ul_attr, implode('', $menu))
        );
    }

    // Create the pick a time dialog
    private function create_pickatime_dialog()
    {
        $rcmail = rcmail::get_instance();

        return html::div(
            ['id' => 'pickatime-dialog', 'class' => 'pickatime-dialog' ],

            html::tag('label', [ 'for' => 'snooze_date' ], $this->gettext('dateLabel'))
            .html::tag('input', [
                'type' => 'date',
                'id' => 'snooze_date',
            ])
            .html::tag('label', [ 'for' => 'snooze_time' ], $this->gettext('timeLabel'))
            .html::tag('input', [
                'type' => 'time',
                'id' => 'snooze_time',
            ])
        );
    }

    // Callback hook when rendering mailbox list
    // (copied from archive plugin)
    function on_render_mailboxlist($p)
    {
        // set localized name for the configured snooze folder
        if ($this->snooze_folder && !rcmail::get_instance()->config->get('show_real_foldernames')) {
            if (isset($p['list'][$this->snooze_folder])) {
                $p['list'][$this->snooze_folder]['name'] = $this->gettext('snoozefolder');
            }
            else {
                // search in subfolders
                $this->_mod_folder_name($p['list'], $this->snooze_folder, $this->gettext('snoozefolder'));
            }
        }

        return $p;
    }

    // Helper method to find the snooze folder in the mailbox tree
    // (copied from archive plugin)
    private function _mod_folder_name(&$list, $folder, $new_name)
    {
        foreach ($list as $idx => $item) {
            if ($item['id'] == $folder) {
                $list[$idx]['name'] = $new_name;
                return true;
            }
            else if (!empty($item['folders'])) {
                if ($this->_mod_folder_name($list[$idx]['folders'], $folder, $new_name)) {
                    return true;
                }
            }
        }

        return false;
    }

    // Callback hook for message_load
    function on_message_load($p)
    {
        // Store the object so we have access to 
        // it in on_template_object_messageheaders
        $this->message = $p['object'];
    }

    // Callback hook to render the message headers
    function on_template_object_messageheaders($p)
    {
        if ($p['class'] == 'header-headers')
        {
            $message = $this->message;
            $rcmail = rcmail::get_instance();

            $snooze = self::parse_snooze_header($message->headers->others['x-snoozed']);
            if ($snooze)
            {
                if ($message->folder == $this->snooze_folder)
                {
                    // In the snooze folder, show "Snoozed" until message
                    $mbox = rcube::JQ($rcmail->output->get_env('mailbox'));
                    $uid = rcube::JQ($rcmail->output->get_env('uid'));
        
                    // Work out date string
                    $datestr = $rcmail->format_date($snooze['until'], $rcmail->action == 'print' ? $rcmail->config->get('date_long', 'x') : null);

                    // Create div with snoozed message and unsnooze button
                    $p['content'] .= html::div('snoozeinfo', 
                        $this->gettext('snoozedUntil').$datestr." "
                        .html::a(
                            [
                                'onclick' => "return rcmail.command('snooze.unsnooze', {_mbox:'$mbox', _uid:'$uid'})",
                                'href' => '#', 
                                'class' => 'button unsnooze',
                            ],
                            rcube::Q($this->gettext('unsnooze'))
                        )
                    );
                }
                else if ($snooze['woken'])
                {
                    // Work out date string
                    $datestr = $rcmail->format_date($snooze['at'], $rcmail->action == 'print' ? $rcmail->config->get('date_long', 'x') : null);

                    // Create div with snoozed message and unsnooze button
                    $p['content'] .= html::div('snoozeinfo snoozewoken', 
                        $this->gettext('snoozed').$datestr
                    );
                }
            }
        }

        return $p;
    }

    // Callback hook to create a new preferences section
    function on_preferences_sections_list($args)
    {
        $this->add_texts('localization');
        $args['list']['snooze'] = [
            'id' => 'snooze',
            'section' => $this->gettext('snoozeSettings')
        ];
        return $args;
    }

    // Callback hook to add the preference options
    function on_preferences_list($args)
    {
        if ($args['section'] != 'snooze') 
            return $args;

        $rcmail = rcmail::get_instance();

        $this->add_texts('localization');

        // Headings
        $args['blocks'] = [
            'main'       => ['name' => rcube::Q($this->gettext('timesOfDay'))],
        ];

        // Morning time
        $input    = new html_inputfield([
                'name'  => 'morningTime',
                'id'    => 'morningTime',
                'type'  => 'time',
                'size'  => 5,
                'class' => 'form-control'
        ]);
        $args['blocks']['main']['options']['morningTime'] = [
            'title'   => html::label('morningTime', rcube::Q($this->gettext('morningTime'))),
            'content' => $input->show($rcmail->config->get('snooze_morning'))
        ];

        // Evening time
        $input    = new html_inputfield([
            'name'  => 'eveningTime',
            'id'    => 'eveningTime',
            'type'  => 'time',
            'size'  => 5,
            'class' => 'form-control'
        ]);
        $args['blocks']['main']['options']['eveningTime'] = [
            'title'   => html::label('eveningTime', rcube::Q($this->gettext('eveningTime'))),
            'content' => $input->show($rcmail->config->get('snooze_evening'))
        ];

        return $args;
    }

    // Callback hook to save preferences
    function on_preferences_save($args)
    {
        if ($args['section'] == 'snooze')
        {
            $args['prefs']['snooze_morning'] = rcube_utils::get_input_value('morningTime', rcube_utils::INPUT_POST);
            $args['prefs']['snooze_evening'] = rcube_utils::get_input_value('eveningTime', rcube_utils::INPUT_POST);
        }
        return $args;
    }

    // Main snooze action handler
    function on_snooze_messages()
    {
        $rcmail = rcmail::get_instance();

        // only process ajax requests
        if (!$rcmail->output->ajax_call) 
            return;

        // Need some strings
        $this->add_texts('localization');

        // Get the snooze time from posted data
        $snooze_till = rcube_utils::get_input_value('snooze_till', rcube_utils::INPUT_POST);

        // Convert to PHP date
        if ($snooze_till != "unsnooze")
        {
            // Convert to PHP date
            $snooze_till = (new DateTime(
                rcube_utils::get_input_value('snooze_till', rcube_utils::INPUT_POST),
                new DateTimeZone($rcmail->config->get('timezone'))
            ))->format("D, d M Y H:i:s O");

            // Work out the snooze tag
            $snoozed_at = (new DateTime())->format("D, d M Y H:i:s O");
            $snooze_tag = "at $snoozed_at;\n    until $snooze_till";
            $to_mbox = $this->snooze_folder;
        }
        else
        {
            $snooze_tag = null;
            $to_mbox = null;
        }

        // Snooze the messages
        $error = false;
        $folders = [];
        foreach (rcmail::get_uids(null, null, $multifolder, rcube_utils::INPUT_POST) as $mbox => $uids) 
        {
            if (!$this->snooze_messages_worker($uids, $mbox, $to_mbox, $snooze_tag, $folders))
            {
                $error = true;
            }
        }

        // update unread messages counts for all involved folders
        foreach (array_keys($folders) as $folder) 
        {
            rcmail_action_mail_index::send_unread_count($folder, true);
        }

        // Refresh list
        if ($from_show_action) 
        {
            if ($next = rcube_utils::get_input_value('_next_uid', rcube_utils::INPUT_GPC)) 
            {
                $rcmail->output->command('show_message', $next);
            }
            else 
            {
                $rcmail->output->command('command', 'list');
            }
        }
        else
        {
            $rcmail->output->command('refresh_list');
        }

        // Show result
        if ($error)
        {
            $rcmail->output->show_message($this->gettext('snoozeError'), 'warning');
        }
        else
        {
            $rcmail->output->show_message($this->gettext('snoozedSuccess'), 'confirmation');
        }

        // Done
        $rcmail->output->send();
    }

    // Move messages to/from snoozed folder
    private function snooze_messages_worker($uids, $from_mbox, $to_mbox, $snooze_tag, &$folders)
    {
        // Get storage
        $storage = rcmail::get_instance()->get_storage();

        // Get the actual UIDs
        if ($uids === '*') 
        {
            $index = $storage->index($from_mbox, null, null, true);
            $uids  = $index->get();
        }

        // For each uid, create a copy of the message, adding (or removing) the snooze header
        foreach ($uids as $uid) 
        {
            // Write the body to the file and the headers to a variable
            $path   = rcube_utils::temp_filename('bounce');
            if ($fp = fopen($path, 'w')) 
            {
                // Get message body and headers
                stream_filter_register('bounce_source', 'rcmail_bounce_stream_filter');
                stream_filter_append($fp, 'bounce_source');
                $storage->set_folder($from_mbox);
                $storage->get_raw_body($uid, $fp);
                fclose($fp);

                // Get message flags
                $headers = $storage->get_message_headers($uid);
                $flags = array_keys($headers->flags);

                // Parse old snooze header
                $old_snooze = self::parse_snooze_header($headers->others['x-snoozed']);

                // If unsnoozing, return to the folder it came from
                if (!$to_mbox && $old_snooze)
                    $to_mbox = $old_snooze['from'];
                if (!$to_mbox)
                    $to_mbox = "INBOX";

                // Work out where the message should be woken to.
                // If this message is already in the snoozed folder, then get the original
                // location from the existing snooze header
                if ($from_mbox == $this->snooze_folder)
                {
                    if ($old_snooze && $old_snooze['from'])
                        $original_mbox = $old_snooze['from'];
                    else
                        $original_mbox = $from_mbox;
                }
                else
                    $original_mbox = $from_mbox;

                // Make sure never restoring to the Snoozed folder
                if ($original_mbox == $this->snooze_folder)
                    $original_mbox = "INBOX";

                // Work out new set of headers by removing the old snooze headers and 
                // adding new header (unless unsnoozing in which case we just remove
                // any old header).
                $headers_str = rcmail_bounce_stream_filter::$headers;
                $headers_str = preg_replace("/^X-Snoozed:.*(\n[ \t]+.*)*\n?/im", "", $headers_str);
                if (substr($headers_str, -1) == "\n")
                    $headers_str = substr($headers_str, 0, -1);
                if ($snooze_tag)
                    $headers_str .= "\nX-Snoozed: $snooze_tag;\n    from $original_mbox";

                // If headers have changed, re-write the message
                if ($headers_str != rcmail_bounce_stream_filter::$headers)
                {
                    // We could save the message directly to the new folder, but by saving in place
                    // it checks we have read/write access to the source folder before trying to move
                    // the message.  This saves accidentally creating two copies of a message in the case
                    // where we create a copy in the target folder and then can't delete the original.

                    // Replace the original message with modified message
                    $new_uid = $storage->save_message($from_mbox, $path, $headers_str, true, $flags);
                    if (!$new_uid)
                        return false;
                    $storage->delete_message($uid, $from_mbox);

                    $uid = $new_uid;
                }

                // Remove temp file
                @unlink($path);

                // Update affected folder map
                $folders[$to_mbox] = true;
                $folders[$from_mbox] = true;

                // Finally, move the new message to the final place
                if ($to_mbox != $from_mbox)
                {
                    if (!$storage->move_message($new_uid, $to_mbox, $from_mbox))
                        return false;
                }
            }
            else
                return false;
        }

        return true;
    }

    // Helper to parse the snooze header
    private static function parse_snooze_header($header)
    {
        if (!$header)
            return false;

        // Split parts
        $result = [];
        foreach (explode(';', str_replace("\n", " ", $header)) as $kv)
        {
            $kv = trim($kv);
            $spacePos = strpos($kv, ' ');
            if ($spacePos > 0)
                $result[substr($kv, 0, $spacePos)] = trim(substr($kv, $spacePos+1));
            else
                $result[$kv] = true;
        }

        return $result;
    }
}

// Copied from program/include/rcmail_resend_mail.php
class rcmail_bounce_stream_filter extends php_user_filter
{
    public static $headers;

    protected $in_body = false;

    public function onCreate()
    {
        self::$headers = '';
    }

    public function filter($in, $out, &$consumed, $closing)
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            if (!$this->in_body) {
                self::$headers .= $bucket->data;
                if (($pos = strpos(self::$headers, "\r\n\r\n")) === false) {
                    continue;
                }

                $bucket->data    = substr(self::$headers, $pos + 4);
                $bucket->datalen = strlen($bucket->data);

                self::$headers = substr(self::$headers, 0, $pos);
                $this->in_body = true;
            }

            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
