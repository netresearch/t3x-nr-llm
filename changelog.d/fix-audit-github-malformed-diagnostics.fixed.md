- Malformed GitHub skill responses retain their typed failure even when the diagnostic logger throws a RuntimeException or Error. Existing bounded diagnostics remain best effort.

- Reject explicitly truncated GitHub repository trees before skill materialization or orphaning; preserve the last known-good skills for repository and marketplace sources.
