# Check of the mails by inbox (HTTP /checkv2), reachable from the host only (port published on 127.0.0.1)
bind_socket = "*:11333";
# The mail is sent in the request only, never a path of a file to read in the container
allow_file_and_shm_inputs = false;
