import re
import subprocess
import datetime
import tempfile
import os
import sys
import argparse
from email.utils import parsedate_to_datetime
from pathlib import Path

# Filter that watches the output from doveadm fetch and creates a temp file with the
# fetch email headers and body.  Also adds the "woken" flag to the snooze header.
class SnoozeFilter:

    def __init__(self, user):
        # Store user
        self.user = user

        # Create temp file
        self.outfile = tempfile.NamedTemporaryFile(mode="w+t", delete = False)

        # Required for doveadm to be able to read the file
        os.chmod(self.outfile.name, 0o666)

    def processHeader(self, key, value):

        # Ignore doveadm formatting
        if key == "text":
            return

        # Find the snooze header and add the "woken" flag
        if key.lower() == "x-snoozed":
            if not re.search("woken", value):
                value = f"{value};\n    woken"

        # Output the header
        self.outfile.write(f"{key}: {value}\n")

    def startBody(self):
        self.outfile.write("\n")

    def body(self, line):
        self.outfile.write(line)
        self.outfile.write("\n")

    def end(self):
        # Flush the file and import to dovecot
        self.outfile.flush()
        self.outfile.close()

    def getFileName(self):
        return self.outfile.name

# Reads an email from a stream, parsing the headers and body parts and 
# passing them to a filter
def processsEmail(infile, filter):

    inheaders = True
    header_key = None
    header_value = None

    while True:
        line = infile.readline().decode('utf-8')
        if not line:
            break
        line = line.rstrip("\r\n")

        if inheaders:

            if line == "":
                # end of headers
                if header_key != None:
                    filter.processHeader(header_key, header_value)
                inheaders = False
                filter.startBody()

            else:
                # append existing header key?
                if header_key != None and (line[0] ==' ' or line[0] == '\t'):
                    header_value += "\n" + line
                    continue

                # is it a header?
                match = re.search("^([^:]+):\s*(.*)$", line)
                if match:
                    if header_key != None:
                        filter.processHeader(header_key, header_value)
                    header_key = match.group(1)
                    header_value = match.group(2)

        else:
            # Send body line
            filter.body(line)

    filter.end()


# Parse a X-Snoozed header
def parse_snooze_header(header):

    # Split into parts and strip white space
    parts = [p.strip() for p in header.split(';')]

    # Process each part
    snooze = {}
    for part in parts:
        kv = [p.strip() for p in part.split(' ', 1)]
        if len(kv) == 2:
            snooze[kv[0]] = kv[1]
        else:
            snooze[kv[0]] = True

    # Done
    return snooze


# Process a user's Snoozed folder
def process_user(user, folder):

    print(f"Processing {user} {folder} folder")

    # Get current time
    now = datetime.datetime.now().timestamp()

    # Helper to check the snooe header and if time to wake, add
    # it to the list of messages to be woken
    expired_messages = []
    def process_header(hdr):
        if hdr:
            snooze = parse_snooze_header(hdr)
            if snooze:
                timestamp = parsedate_to_datetime(snooze['until']).timestamp()
                if timestamp < now:
                    expired_messages.append({ 'uid': uid, 'from': snooze['from']})

    # Ask for all messages with snooze header
    proc = subprocess.Popen(f"doveadm fetch -u {user} \'uid hdr.x-snoozed\' mailbox {folder}", shell=True, stdout=subprocess.PIPE)

    # Read lines from dovadm
    uid = None
    snooze_hdr = None
    while True:

        # Read a line
        line = proc.stdout.readline().decode('utf-8').rstrip("\n")
        if not line:
            break
        
        # If it's the the 'uid:' line, capture the uid
        match = re.search("^uid:\s(.*)$", line)
        if match:
            uid = match.group(1)
            continue

        # If it's the snooze header, capture it
        match = re.search("^hdr.x-snoozed:(.*)", line)
        if match and uid:
            snooze_hdr = match.group(1)
            continue

        # Continue capturing the snooze header
        if snooze_hdr != None and (line[0] == ' ' or line[0] == '\t'):
            snooze_hdr += line
            continue

        # Proces any received snooze header
        process_header(snooze_hdr)
        snooze_hdr = None
        uid = None

    # Proces any trailing snooze header
    process_header(snooze_hdr)
            
    # Wait for process to finish
    proc.wait()

    # Quit if nothing to unsnooze
    if len(expired_messages) == 0:
        return

    # Unsnooze
    print(f"Unsnoozing {len(expired_messages)} message(s)...")
    for msg in expired_messages:

        # Export the message through our filter to a temp file
        proc = subprocess.Popen(['doveadm', 'fetch', '-u', user, 'text', 'mailbox', folder, 'uid', msg['uid']], stdout=subprocess.PIPE)
        filter = SnoozeFilter(user)
        processsEmail(proc.stdout, filter)
        proc.wait()

        # Check exported ok
        if proc.returncode != 0:
            sys.stderr.write(f"Failed to export snoozed message:\n{proc.args}\n")
            return

        # Import from temp file
        proc = subprocess.run(['doveadm', 'save', '-u', user, '-m', msg['from'], filter.getFileName()], check=False)

        # If import failed and trying to import to a mailbox other than the INBOX, try again using INBOX
        if proc.returncode != 0 and msg['from'].upper() != 'INBOX':
            proc = subprocess.run(['doveadm', 'save', '-u', user, '-m', 'INBOX', filter.getFileName()], check=False)

        # Quit if couldn't import
        if proc.returncode != 0:
            sys.stderr.write(f"Failed to import snoozed message:\n{proc.args}\n")
            return

        # Remove temp file
        os.remove(filter.getFileName())

        # Delete the email from the snooze folder
        proc = subprocess.run(['doveadm', 'expunge', '-u', user, 'mailbox', folder, 'uid', msg['uid']], check = False)
        if proc.returncode != 0:
            sys.stderr.write(f"Failed to delete original snoozed message:\n{proc.args}\n")
            return


# Process all users
def process_all_users(snooze_mbox, exclude_users):

    # Get all Snooze folders
    snooze_folders = subprocess.check_output(['doveadm', 'mailbox', 'list', '-A', 'mailbox', snooze_mbox]).decode('utf-8').rstrip("\n").split('\n')

    # Process all folders
    for snooze_folder in snooze_folders:

        if len(snooze_folder) > 0:
            # Split into user name and folder
            user, folder = snooze_folder.split()

            if exclude_users and user in exclude_users:
                continue

            process_user(user, folder)



#### Main ####

# Parse command line args
parser = argparse.ArgumentParser()
parser.add_argument('--mbox', help = "name of the Snoozed message mailbox")
parser.add_argument('--users', nargs = '+', metavar = "USER", help = "an explicit list of users (omit to process all users)")
parser.add_argument('--exclude', nargs = '+', metavar = "USER", help = "users to exclude")
args = parser.parse_args()

# Can't specify by users and exclude
if args.users and args.exclude:
    sys.stderr.write("Options --users or --exclude can't be used together")
    os._exit(2)

# If the command line didn't specify the name of the Snoozed folder, then
# look in the config.inc.php file for `$config['snooze_mbox'] = "something"`
if not args.mbox:
    try:
        cfgfile = str(Path(__file__).parent.joinpath("config.inc.php"));
        file = open(cfgfile, "r")
        if file:
            content = file.read()
            file.close()
            match = re.search("\$config\[\s?['\"]snooze_mbox['\"]\s?\]\s?=\s?['\"](.+)['\"]", content)
            if match:
                args.mbox = match.group(1)
    except IOError:
        pass    # don't care

# Fallback to default
if not args.mbox:
    args.mbox = "Snoozed"

if args.users:
    # Process just the specified users
    for user in args.users:
        process_user(user, args.mbox)
else:
    # Process all users...
    process_all_users(args.mbox, args.exclude)

