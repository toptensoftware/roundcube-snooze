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

        if ($rcmail->task == 'mail' && ($rcmail->action == '' || $rcmail->action == 'show') && $this->snooze_folder) {
            $this->include_stylesheet($this->local_skin_path() . '/snooze.css');
            $this->include_script('snooze.js');
            $this->add_texts('localization', true);

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
        else if ($rcmail->task == 'mail') {
            // handler for ajax request
            $this->register_action('plugin.snooze_messages', [$this, 'snooze_messages']);
        }
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
            'nextweek', 'nextweekend', 'pickatime'
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
        if (!$rcmail->output->ajax_call) {
            return;
        }
/*
        $rcmail->output->show_message(
            rcube_utils::get_input_value('snooze_till', rcube_utils::INPUT_POST),
            "confirmation");
        $rcmail->output->send();
        return;
*/

        // Get the snooze time from posted data
        $snooze_till = (new DateTime(
            rcube_utils::get_input_value('snooze_till', rcube_utils::INPUT_POST),
            new DateTimeZone($rcmail->config->get('timezone'))
        ))->format("U");

        // Work out the snooze tag
        $snoozed_at = (new DateTime())->format("U");
        $snooze_tag = 'SNOOZED_AT_'.$snoozed_at.'_TILL_'.$snooze_till;

        $this->add_texts('localization');

        $storage        = $rcmail->get_storage();
        $delimiter      = $storage->get_hierarchy_delimiter();
        $threading      = (bool) $storage->get_threading();
        $search_request = rcube_utils::get_input_value('_search', rcube_utils::INPUT_GPC);
        $from_show_action = !empty($_POST['_from']) && $_POST['_from'] == 'show';

        // count messages before changing anything
        $old_count = 0;
        if (!$from_show_action) {
            $old_count = $storage->count(null, $threading ? 'THREADS' : 'ALL');
        }

        $sort_col = rcmail_action_mail_index::sort_column();
        $sort_ord = rcmail_action_mail_index::sort_order();
        $count    = 0;
        $uids     = null;

        // this way response handler for 'move' action will be executed
        $rcmail->action = 'move';
        $this->result   = [
            'error'        => false,
            'sources'      => [],
            'destinations' => [],
        ];

        // Snooze messages
        foreach (rcmail::get_uids(null, null, $multifolder, rcube_utils::INPUT_POST) as $mbox => $uids) 
        {
            $count += $this->snooze_messages_worker($uids, $mbox, $this->snooze_folder, $snooze_tag);
        }

        if ($this->result['error']) {
            if (!$from_show_action) {
                $rcmail->output->command('list_mailbox');
            }

            $rcmail->output->show_message($this->gettext('snoozeerror'), 'warning');
            $rcmail->output->send();
        }

        if (!empty($_POST['_refresh'])) {
            // FIXME: send updated message rows instead of reloading the entire list
            $rcmail->output->command('refresh_list');
            $addrows = false;
        }
        else {
            $addrows = true;
        }

        // refresh saved search set after moving some messages
        if ($search_request && $rcmail->storage->get_search_set()) {
            $_SESSION['search'] = $rcmail->storage->refresh_search();
        }

        if ($from_show_action) {
            if ($next = rcube_utils::get_input_value('_next_uid', rcube_utils::INPUT_GPC)) {
                $rcmail->output->command('show_message', $next);
            }
            else {
                $rcmail->output->command('command', 'list');
            }

            $rcmail->output->send();
        }

        $mbox           = $storage->get_folder();
        $msg_count      = $storage->count(null, $threading ? 'THREADS' : 'ALL');
        $exists         = $storage->count($mbox, 'EXISTS', true);
        $page_size      = $storage->get_pagesize();
        $page           = $storage->get_page();
        $pages          = ceil($msg_count / $page_size);
        $nextpage_count = $old_count - $page_size * $page;
        $remaining      = $msg_count - $page_size * ($page - 1);
        $quota_root     = $multifolder ? $this->result['sources'][0] : 'INBOX';
        $jump_back      = false;

        // jump back one page (user removed the whole last page)
        if ($page > 1 && $remaining == 0) {
            $page -= 1;
            $storage->set_page($page);
            $_SESSION['page'] = $page;
            $jump_back = true;
        }

        // update unread messages counts for all involved folders
        foreach ($this->result['sources'] as $folder) {
            rcmail_action_mail_index::send_unread_count($folder, true);
        }

        // update message count display
        $rcmail->output->set_env('messagecount', $msg_count);
        $rcmail->output->set_env('current_page', $page);
        $rcmail->output->set_env('pagecount', $pages);
        $rcmail->output->set_env('exists', $exists);
        $rcmail->output->command('set_quota', rcmail_action::quota_content(null, $quota_root));
        $rcmail->output->command('set_rowcount', rcmail_action_mail_index::get_messagecount_text($msg_count), $mbox);

        if ($threading) {
            $count = rcube_utils::get_input_value('_count', rcube_utils::INPUT_POST);
        }

        // add new rows from next page (if any)
        if ($addrows && $count && $uids != '*' && ($jump_back || $nextpage_count > 0)) {
            // #5862: Don't add more rows than it was on the next page
            $count = $jump_back ? null : min($nextpage_count, $count);

            $a_headers = $storage->list_messages($mbox, null, $sort_col, $sort_ord, $count);

            rcmail_action_mail_index::js_message_list($a_headers, false);
        }

        $rcmail->output->show_message($this->gettext('snoozed'), 'confirmation');

        /*
        if (!$read_on_move) {
            foreach ($this->result['destinations'] as $folder) {
                rcmail_action_mail_index::send_unread_count($folder, true);
            }
        }
        */

        // send response
        $rcmail->output->send();
    }

    private static function parse_snooze_tag($tag)
    {
        if (preg_match("/^SNOOZED_AT_(\d+)_TILL_(\d+)$/", $tag, $matches))
        {
            return [
                'snoozed' => $matches[1],
                'till' => $matches[2],
            ];
        }
        else
            return false;
    }

    /**
     * Move messages from one folder to another and mark as read if needed
     */
    private function snooze_messages_worker($uids, $from_mbox, $to_mbox, $snooze_tag)
    {
        $storage = rcmail::get_instance()->get_storage();

        // Remove any existing SNOOZED tags
        $uids2 = array_replace([], $uids);
        if ($uids2 === '*') 
        {
            $index = $imap->index($mbox, null, null, true);
            $uids2  = $index->get();
        }
        foreach ($uids2 as $uid) 
        {
            $headers = $storage->get_message_headers($uid);
            $flags = array_keys($headers->flags);
            $snoozed_flags = array_filter($flags, function($tag) {
                return self::parse_snooze_tag($tag) !== false;
            });
            foreach ($snoozed_flags as $flag)
            {
                $storage->set_flag($uid, "UN$flag", $from_mbox, true);
            }
        }

        // Set the snooze tag
        $storage->set_flag($uids, $snooze_tag, $from_mbox, true);

        // Don't move message if not necessary
        if ($from_mbox == $to_mbox)
            return 0;
        
        // move message to target folder
        if ($storage->move_message($uids, $to_mbox, $from_mbox)) 
        {
            if (!in_array($from_mbox, $this->result['sources'])) {
                $this->result['sources'][] = $from_mbox;
            }
            if (!in_array($to_mbox, $this->result['destinations'])) {
                $this->result['destinations'][] = $to_mbox;
            }

            return count($uids);
        }

        $this->result['error'] = true;
    }
}
