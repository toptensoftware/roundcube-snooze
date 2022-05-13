/**
 * Snooze plugin script
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * Copyright (C) Topten Software
 *
 * The JavaScript code in this page is free software: you can redistribute it
 * and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, either version 3 of
 * the License, or (at your option) any later version.
 *
 * @licend  The above is the entire license notice
 * for the JavaScript code in this file.
 */

if (window.rcmail)
{
    let intervals = [
        'latertoday', 'tomorrow', 'laterthisweek', 'thisweekend',
        'nextweekend', 'nextweek', 'pickatime'
    ];

    rcmail.addEventListener('init', function (evt)
    {
        // Active?
        if (rcmail.env.snooze_folder)
        {
            register_commands();
            update_snooze_menu();
            style_snooze_folder();
        }

        // Register the various snooze interval commands
        function register_commands()
        {
            for (let i = 0; i < intervals.length; i++)
            {
                // Register commands
                rcmail.register_command("snooze." + intervals[i], function (props, obj, event) { 
                    on_snooze_messages(intervals[i]) 
                }, rcmail.env.uid);
            }            

            rcmail.env.message_commands.push("snooze.unsnooze");
            rcmail.register_command("snooze.unsnooze", function(props, obj, event) {
                on_unsnooze_message(props);
            }, rcmail.env.uid);

            if (rcmail.message_list) 
            {
                rcmail.message_list.addEventListener('select', enable_commands);
            }
        }

        function enable_commands(list)
        {
            let enabled = list.get_selection(false).length > 0;
            for (let i = 0; i < intervals.length; i++)
            {
                let cmd_enabled = enabled && calculate_snooze_till_time(intervals[i]) != null;
                if (intervals[i] == "pickatime")
                    cmd_enabled = enabled;

                // Register commands
                rcmail.enable_command("snooze." + intervals[i], cmd_enabled);
            }

            rcmail.enable_command("snooze.unsnooze", enabled && is_snooze_folder());
        }

        // Install a handler so that when the snooze menu is opened
        // the times for each interval are shown and those that aren't
        // applicable are hidden.
        function update_snooze_menu()
        {
            window.snoozemenu_init = function(popup, item, event)
            {
                $(popup).find('a').each(function() 
                {
                    // Find the span and add it if not found
                    let span = $(this).find('span')[0];
                    if (!span)
                        span = $("<span style='float:right; font-size:smaller'></span>").appendTo(this);

                    // Calculate date
                    let snooze_interval = this.id.substring(7);
                    if (snooze_interval == "pickatime")
                        return;
                    if (snooze_interval ==  "unsnooze")
                    {
                        // Hide/show the element
                        if (!is_snooze_folder())
                            $(this).parent().addClass('hidden');
                        else
                            $(this).parent().removeClass('hidden');
                        return;
                    }

                    // Work out the snooze time
                    let snooze_till_time = calculate_snooze_till_time(snooze_interval);

                    // Hide/show the element
                    if (!snooze_till_time)
                        $(this).parent().addClass('hidden');
                    else
                        $(this).parent().removeClass('hidden');

                    // Show the time
                    $(span).html(php_format_date("D, " + rcmail.env.snooze_time_format, snooze_till_time));
                });
            };
        }

        // Apply CSS styling to the snooze folder
        function style_snooze_folder()
        {
            var li;
            if (rcmail.subscription_list)
            {
                // Settings > Folders
                li = rcmail.subscription_list.get_item(rcmail.env.snooze_folder);
            }
            else
            {
                // Folder list
                li = rcmail.get_folder_li(rcmail.env.snooze_folder, '', true);
            }
            if (li)
                $(li).addClass('snoozed');

            // Folder selector popup
            rcmail.addEventListener('menu-open', function (p)
            {
                if (p.name == 'folder-selector')
                {
                    var search = rcmail.env.snooze_folder;
                    $('a', p.obj).filter(function () { return $(this).data('id') == search; }).parent().addClass('snoozed');
                }
            });
        }

        // Check if we're viewing the snoozed folder
        function is_snooze_folder()
        {
            // check if current folder is an snooze folder
            return rcmail.env.mailbox == rcmail.env.snooze_folder;
        }

        // Prompt for snooze wake up time
        function prompt_snooze_time()
        {
            // Create the dialog
            var dlg = rcmail.simple_dialog(
                $('#pickatime-dialog').clone(true), 
                rcmail.gettext('snooze.pickatime'), 
                on_ok, 
                {
                    button: rcmail.gettext('snooze.snoozeButton'),
                    closeOnEscape: true,
                    width:250,
                    height: 160,
                }
            );

            $(dlg).keypress(function(e) {
                if (e.keyCode == $.ui.keyCode.ENTER) {
                    $(dlg).parent().find(".mainaction")[0].click();
                }
            });

            // Find the two fields
            let date_field = $(dlg).find("#snooze_date")[0];
            let time_field = $(dlg).find("#snooze_time")[0];
            
            // Initialize fields
            let tomorrow = calculate_snooze_till_time("tomorrow");
            date_field.value = php_format_date("Y-m-d", tomorrow);
            time_field.value = php_format_date("H:i", tomorrow);

            // Helper to get the selected date
            function get_entered_date()
            {
                let d = date_field.valueAsDate;
                let t = parse_time(time_field.value);

                return new Date(
                    d.getFullYear(), d.getMonth(), d.getDate(),
                    Math.floor(t/60), t % 60,
                    );
            }

            // Called when the OK button is pressed
            function on_ok(e) 
            {
                let date = get_entered_date();
                if (!date || date < new Date())
                {
                    alert(rcmail.gettext('snooze.invalidDate'));
                    return false;
                }
                snooze_messages_till(date);
                return true;
            };
        };

    
        // Handle for the snooze commands
        function on_snooze_messages(snooze_interval)
        {
            if (snooze_interval == "pickatime")
            {
                prompt_snooze_time();
            }
            else if (snooze_interval == "unsnooze")
            {
                snooze_messages_till("unsnooze");
            }
            else
            {
                snooze_messages_till(calculate_snooze_till_time(snooze_interval));
            }
        }

        // Handler for unsnooze command
        function on_unsnooze_message(props)
        {
            if (!props)
                props = rcmail.selection_post_data();

            props.snooze_till = "unsnooze";
            send_snooze_command(props);
        }

        // Snooze the selected messages
        function snooze_messages_till(date)
        {
            if (!date)
                return;

            // Get the selection and quit if empty
            var post_data = rcmail.selection_post_data();
            if (!post_data._uid)
                return;
        
            // Pass the timestamp
            post_data.snooze_till = php_format_date('d-m-Y H:i', date);

            // Send it..
            send_snooze_command(post_data);
        }

        function send_snooze_command(post_data)
        {
            // Display message
            lock = rcmail.display_message(rcmail.gettext('snooze.snoozingmessages'), 'loading');

            // send request to server
            rcmail.http_post('plugin.snooze_messages', post_data, lock);
                  
            // Reset preview
            rcmail.show_contentframe(false);
        }

        // Calculate the wake up time for a specified interval type (based on the current time)
        //
        // Note the returned date object will be in the user selected time zone even though
        // the date object doesn't support time zones.  If you format the date to a string it 
        // will be the correct result for the user's time zone. (totally confusing I know)
        function calculate_snooze_till_time(interval, from)
        {
            if (!from)
                from = new Date();

            // Get the user selected time zone
            let timezone = rcmail.env.snooze_time_zone;

            // Get current time in user's timezone
            let now = new Date(from.toLocaleString("en-US", {timeZone: timezone}));
            let timeminutes = now.getHours() * 60 + now.getMinutes();
            let eveningTime = parse_time(rcmail.env.snooze_evening);
            let morningTime = parse_time(rcmail.env.snooze_morning);
            let dow = now.getDay();

            // Calculate when the wake up time should be
            switch (interval)
            {
                case 'latertoday':
                    if (timeminutes < morningTime)
                        return null;
                    if (timeminutes >= eveningTime)
                        return null;
                    else
                        return new Date(now.getFullYear(), now.getMonth(), now.getDate(), parseInt(eveningTime/60), eveningTime%60);
                    return ;

                case 'tomorrow':
                    if (timeminutes < morningTime)
                        return new Date(now.getFullYear(), now.getMonth(), now.getDate(), parseInt(morningTime/60), morningTime%60);
                    else
                        return new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, parseInt(morningTime/60), morningTime%60);

                case 'laterthisweek':
                {
                    if (dow < 1 || dow > 2)
                        return null;
                    let offset = 3 - dow;
                    if (offset <= 0) return null;
                    return new Date(now.getFullYear(), now.getMonth(), now.getDate() + offset, parseInt(morningTime/60), morningTime%60);
                }

                case 'thisweekend':
                {
                    if (dow == 0 || dow == 6)
                        return null;
                    let offset = 6 - now.getDay();
                    return new Date(now.getFullYear(), now.getMonth(), now.getDate() + offset, parseInt(morningTime/60), morningTime%60);
                }

                case 'nextweekend':
                {
                    if (dow != 0 && dow != 6)
                        return null;
                    let offset = 6 - dow;
                    if (offset <= 0)
                        offset += 7;
                    return new Date(now.getFullYear(), now.getMonth(), now.getDate() + offset, parseInt(morningTime/60), morningTime%60);
                }

                case 'nextweek':
                {
                    let offset = 1 - dow;
                    if (dow == 0)
                        offset++;
                    if (offset <= 0)
                        offset += 7;
                    return new Date(now.getFullYear(), now.getMonth(), now.getDate() + offset, parseInt(morningTime/60), morningTime%60);
                }

                return null;
            }
        }

        // Parse a time in HH:MM format into a number of minutes
        function parse_time(value)
        {
            if (!value)
                return 0;   
            let parts = value.split(':');
            if (parts.length < 1)
                return 0;

            let minutes = parseInt(parts[0]) * 60;
            if (parts.length > 1)
                minutes += parseInt(parts[1]);
            
            return minutes;
        }

        // Format a date similar to PHP's date formatting
        // Based on this: https://gist.github.com/williamd5/56904a0a505fd8e18c646398e94135a6
        // plus added 'G' and 'A' support
        function php_format_date(format, date)
        {
            if (!date)
                return "N/A";
            if (!date || date === "") date = new Date();
            else if (!(date instanceof Date)) date = new Date(date.replace(/-/g, "/")); // attempt to convert string to date object

            let string = '',
                mo = date.getMonth(), // month (0-11)
                m1 = mo + 1, // month (1-12)
                dow = date.getDay(), // day of week (0-6)
                d = date.getDate(), // day of the month (1-31)
                y = date.getFullYear(), // 1999 or 2003
                h = date.getHours(), // hour (0-23)
                mi = date.getMinutes(), // minute (0-59)
                s = date.getSeconds(); // seconds (0-59)

            for (let i of format.match(/(\\)*./g))
                switch (i)
                {
                    case 'j': // Day of the month without leading zeros  (1 to 31)
                        string += d;
                        break;

                    case 'd': // Day of the month, 2 digits with leading zeros (01 to 31)
                        string += (d < 10) ? "0" + d : d;
                        break;

                    case 'l': // (lowercase 'L') A full textual representation of the day of the week
                        var days = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
                        string += days[dow];
                        break;

                    case 'w': // Numeric representation of the day of the week (0=Sunday,1=Monday,...6=Saturday)
                        string += dow;
                        break;

                    case 'D': // A textual representation of a day, three letters
                        var days = ["Sun", "Mon", "Tue", "Wed", "Thr", "Fri", "Sat"];
                        string += days[dow];
                        break;

                    case 'm': // Numeric representation of a month, with leading zeros (01 to 12)
                        string += (m1 < 10) ? "0" + m1 : m1;
                        break;

                    case 'n': // Numeric representation of a month, without leading zeros (1 to 12)
                        string += m1;
                        break;

                    case 'F': // A full textual representation of a month, such as January or March 
                        var months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
                        string += months[mo];
                        break;

                    case 'M': // A short textual representation of a month, three letters (Jan - Dec)
                        var months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                        string += months[mo];
                        break;

                    case 'Y': // A full numeric representation of a year, 4 digits (1999 OR 2003)	
                        string += y;
                        break;

                    case 'y': // A two digit representation of a year (99 OR 03)
                        string += y.toString().slice(-2);
                        break;

                    case 'h': // 12-hour format of an hour with leading zeros (01 to 12)
                        var hour = (h === 0) ? 12 : h;
                        hour = (hour > 12) ? hour - 12 : hour;
                        string += (hour < 10) ? "0" + hour : hour;
                        break;

                    case 'H': // 24-hour format of an hour with leading zeros (00 to 23)
                        string += (h < 10) ? "0" + h : h;
                        break;

                    case 'g': // 12-hour format of an hour without leading zeros (1 to 12)
                        var hour = (h === 0) ? 12 : h;
                        string += (hour > 12) ? hour - 12 : hour;
                        break;

                    case 'G': // 24-hour format of an hour without leading zeros (0 to 23)
                        string += h;
                        break;

                    case 'a': // Lowercase Ante meridiem and Post meridiem (am or pm)
                        string += (h < 12) ? "am" : "pm";
                        break;

                    case 'A': // Uppercase Ante meridiem and Post meridiem (am or pm)
                        string += (h < 12) ? "AM" : "PM";
                        break;

                    case 'i': // Minutes with leading zeros (00 to 59)
                        string += (mi < 10) ? "0" + mi : mi;
                        break;

                    case 's': // Seconds, with leading zeros (00 to 59)
                        string += (s < 10) ? "0" + s : s;
                        break;

                    case 'c': // ISO 8601 date (eg: 2012-11-20T18:05:54.944Z)
                        string += date.toISOString();
                        break;

                    default:
                        if (i.startsWith("\\")) i = i.substr(1);
                        string += i;
                }

            return string;
        }

    });
}

