import re
import subprocess
import datetime
import tempfile
import os
from email.utils import parsedate_to_datetime

class TestFilter:

    def __init__(self, user):
        self.user = user
        self.outfile = tempfile.NamedTemporaryFile(mode="w+t")
        os.chmod(self.outfile.name, 0o666)
        print(f"writing to temp file {self.outfile.name}")

    def processHeader(self, key, value):

        # Ignore doveadm formatting
        if key == "text":
            return

        # Find the snooze header and add the "woken" flag
        if key == "RoundCube-Snooze":
            match = re.search("^snoozed at (.+) until ([^;]+)$", value)
            if match:
                value = f"{match.group(0)}; woken"

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
        subprocess.run(['doveadm', 'save', '-u', self.user, '-m', 'inbox', self.outfile.name], check=True)

# Reads an email from a file, parsing the headers and body parts and 
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


def process_user(user, folder):
    print(f"Processing {user} {folder} folder")

    # Ask for all messages with snooze header
    proc = subprocess.Popen(f"doveadm fetch -u {user} \'uid hdr.RoundCube-Snooze\' mailbox {folder}", shell=True, stdout=subprocess.PIPE)

    # Get current time
    now = datetime.datetime.now().timestamp()

    uids = []

    # Read lines from dovadm
    uid = None
    while True:
        # Read a line
        line = proc.stdout.readline().decode('utf-8').rstrip("\n")
        if not line:
            break
        
        # If it's the the 'uid:' line, just store it
        match = re.search("^uid:\s(.*)$", line)
        if match:
            uid = match.group(1)
            continue

        # If it's the snooze header, compare to current time and check if wake time yet
        match = re.search("^hdr.roundcube-snooze:\ssnoozed at (.*) until ([^;]+)", line)
        if match and uid:
            timestamp = parsedate_to_datetime(match.group(2)).timestamp()
            if timestamp < now:
                uids.append(uid)

        uid = None

            
    # Wait for process to finish
    proc.wait()

    # Quit if nothing to unsnooze
    if len(uids) == 0:
        return;

    # Unsnooze
    print(f"Unsnoozing {len(uids)} message(s)...")
    for uid in uids:

        # Export the message to a temp file
        proc = subprocess.Popen(['doveadm', 'fetch', '-u', user, 'text', 'mailbox', folder, 'uid', uid], stdout=subprocess.PIPE)

        # Pipe output through our email filter
        processsEmail(proc.stdout, TestFilter(user))

        # Wait till finished
        proc.wait()

        # Delete the email from the snooze folder
        subprocess.run(['doveadm', 'expunge', '-u', user, 'mailbox', folder, 'uid', uid], check = True)


def process_all_users():

    # Get all Snooze folders
    snooze_folders = subprocess.check_output(['doveadm', 'mailbox', 'list', '-A', 'mailbox', 'Snoozed']).decode('utf-8').rstrip("\n").split('\n')

    # Process all folders
    for snooze_folder in snooze_folders:

        # Split into user name and folder
        user, folder = snooze_folder.split()
        process_user(user, folder)



process_all_users()

