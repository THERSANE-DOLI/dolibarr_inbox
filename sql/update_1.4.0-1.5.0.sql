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

-- Lot W3 (1.5.0): key of the conversation of each mail (computed by the synchronisation, the mails already indexed
-- by small batches)
ALTER TABLE llx_inbox_mail ADD COLUMN thread_key varchar(32) DEFAULT NULL;
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_thread (fk_account, thread_key);
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_inreplyto (fk_account, in_reply_to);
