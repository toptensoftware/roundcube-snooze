<?php

/**
 * Snooze
 *
 * Plugin that adds a new button to the mailbox toolbar
 * to move messages to a (user selectable) snooze folder.
 *
 * @version 3.2
 * @license GNU GPLv3+
 * @author Andre Rodier, Thomas Bruederli, Aleksander Machniak
 */
class snooze extends rcube_plugin
{
    public $task = 'mail';

    private $snooze_folder;
    private $folders;
    private $result;


    /**
     * Plugin initialization.
     */
    function init()
    {
        $rcmail = rcmail::get_instance();

        $this->load_config();

        $this->snooze_folder = $rcmail->config->get('snooze_mbox');

        if ($rcmail->task == 'mail') 
        {
            $this->add_texts('localization', true);

            // we need to request the snooze headers when fetching emails
            $this->add_hook('storage_init', [$this, 'storage_init']);

            // handler for ajax request
            $this->register_action('plugin.snooze_messages', [$this, 'snooze_messages']);

            // handle to show snooze status
            $this->add_hook('message_body_prefix', [$this, 'on_message_body_prefix']);

            $this->include_stylesheet($this->local_skin_path() . '/snooze.css');
        }

        if ($rcmail->task == 'mail' && ($rcmail->action == '' || $rcmail->action == 'show') && $this->snooze_folder) 
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

            // register hook to localize the snooze folder
            $this->add_hook('render_mailboxlist', [$this, 'render_mailboxlist']);

            // set env variables for client
            $rcmail->output->set_env('snooze_folder', $this->snooze_folder);
            $rcmail->output->set_env('snooze_time_format', $rcmail->config->get('time_format'));
            $rcmail->output->set_env('snooze_time_zone', $rcmail->config->get('timezone'));

            // Options menu contents
            $rcmail->output->add_footer($this->create_snooze_menu());
            $rcmail->output->add_footer($this->create_pickatime_dialog());
        }
    
    }


    function storage_init($p)
    {
        $rcmail = rcmail::get_instance();

        // when fetching mail headers, also get the snooze header
        // so we can display snooze info on emails
        $p['fetch_headers'] = $p['fetch_headers'] . ' ROUNDCUBE-SNOOZE';

        return $p;
    }

    /**
     * Init compose UI (add task button and the menu)
     */
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
                html::tag('h3', ['id' => 'aria-label-snoozemenu'], "Snooze until...")
                . html::tag('ul', $ul_attr, implode('', $menu))
        );
    }


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



    /**
     * Hook to give the snooze folder a localized name in the mailbox list
     */
    function render_mailboxlist($p)
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


    function on_message_body_prefix($p)
    {
        $rcmail = rcmail::get_instance();

        $message = $p['message'];

        // Only display snoozed headers on the first part
        if ($message->parts[0] == $p['part'])
        {
            $snooze = self::parse_snooze_header($message->headers->others['roundcube-snooze']);
            if ($snooze)
            {
                if ($message->folder == $this->snooze_folder)
                {
                    // In the snooze folder, show "Snoozed" until message
                    $mbox = rcube::JQ($rcmail->output->get_env('mailbox'));
                    $uid = rcube::JQ($rcmail->output->get_env('uid'));
        
                    // Work out date string
                    $datestr = $rcmail->format_date($snooze['till'], $rcmail->action == 'print' ? $rcmail->config->get('date_long', 'x') : null);

                    // Create div with snoozed message and unsnooze button
                    $p['prefix'] .= html::div('snoozebox', 
                        "Snoozed until $datestr. "
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
                    $datestr = $rcmail->format_date($snooze['snoozed'], $rcmail->action == 'print' ? $rcmail->config->get('date_long', 'x') : null);

                    // Create div with snoozed message and unsnooze button
                    $p['prefix'] .= html::div('snoozebox', 
                        "Snoozed $datestr. "
                    );
                }
            }
        }
        return $p;
    }

    /**
     * Helper method to find the snooze folder in the mailbox tree
     */
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

    /**
     * Plugin action to move the submitted list of messages to the snooze subfolders
     * according to the user settings and their headers.
     */
    function snooze_messages()
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
            $snooze_tag = 'snoozed at '.$snoozed_at.' until '.$snooze_till;
            $to_mbox = $this->snooze_folder;
        }
        else
        {
            $snooze_tag = null;
            $to_mbox = "INBOX";
        }


        // Snooze messages
        $error = false;
        $folders = [ $to_mbox ];
        foreach (rcmail::get_uids(null, null, $multifolder, rcube_utils::INPUT_POST) as $mbox => $uids) 
        {
            $folders[] = $from_mbox;
            if (!$this->snooze_messages_worker($uids, $mbox, $to_mbox, $snooze_tag))
            {
                $error = true;
            }
        }

        // update unread messages counts for all involved folders
        foreach ($folders as $folder) 
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
            $rcmail->output->show_message($this->gettext('snoozeerror'), 'warning');
        }
        else
        {
            $rcmail->output->show_message($this->gettext('snoozed'), 'confirmation');
        }

        // Done
        $rcmail->output->send();
    }

    /**
     * Move messages from one folder to another and mark as read if needed
     */
    private function snooze_messages_worker($uids, $from_mbox, $to_mbox, $snooze_tag)
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
                $flags = array_keys($storage->get_message_headers($uid)->flags);

                // Work out new set of headers by removing the old snooze headers and 
                // adding new header (unless unsnoozing in which case we just remove
                // any old header).
                $headers_str = rcmail_bounce_stream_filter::$headers;
                $headers_str = preg_replace("/^RoundCube-Snooze: snoozed at (.+) until (.+)\n?/mi", "", $headers_str);
                if (substr($headers_str, -1) == "\n")
                    $headers_str = substr($headers_str, 0, -1);
                if ($snooze_tag)
                    $headers_str .= "\nRoundCube-Snooze: $snooze_tag";

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

                // Finally, move the new message to the final place
                if (!$storage->move_message($new_uid, $to_mbox, $from_mbox))
                    return false;
            }
            else
                return false;
        }

        return true;
    }

    private static function parse_snooze_header($header)
    {
        if (preg_match("/^snoozed at (.+) until ([^;]+)(; woken)?$/", $header, $matches))
        {
            return [
                'snoozed' => new DateTime($matches[1]),
                'till' => new DateTime($matches[2]),
                'woken' => !!$matches[3],
            ];
        }
        else
            return false;
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
