-- Copyright (C) 2026	John BOTELLA
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

ALTER TABLE llx_inbox_mail ADD UNIQUE INDEX uk_inbox_mail_remote (fk_folder, remote_id);
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_list (fk_folder, date_received);
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_sort (fk_folder, sort_key);
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_msgid (message_id);
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_account (fk_account);
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_from (from_email);
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_soc (fk_soc);
-- The application asks every 20 s whether the open folder changed
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_tms (fk_folder, tms);
-- Conversations (1.5.0): mails of a thread, answers of a mail
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_thread (fk_account, thread_key);
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_inreplyto (fk_account, in_reply_to);
