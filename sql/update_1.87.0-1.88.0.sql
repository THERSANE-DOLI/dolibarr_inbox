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

-- 1.88.0: verdict of the anti-spam engine of inbox (Rspamd) on the new mails of the inbox; spam_check NULL = not
-- concerned, 0 = to check, 1 = checked
ALTER TABLE llx_inbox_mail ADD COLUMN spam_check smallint DEFAULT NULL;
ALTER TABLE llx_inbox_mail ADD COLUMN spam_engine varchar(16) DEFAULT NULL;
ALTER TABLE llx_inbox_mail ADD COLUMN spam_score double DEFAULT NULL;
ALTER TABLE llx_inbox_mail ADD COLUMN spam_required double DEFAULT NULL;
ALTER TABLE llx_inbox_mail ADD COLUMN spam_action varchar(32) DEFAULT NULL;
ALTER TABLE llx_inbox_mail ADD COLUMN spam_symbols text DEFAULT NULL;
-- Index on a new column: here, the .key.sql runs before this file
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_spam_check (fk_account, spam_check);
