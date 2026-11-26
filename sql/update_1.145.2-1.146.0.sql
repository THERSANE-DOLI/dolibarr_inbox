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



-- Journal of the actions (1.146.0): an action of a module subscribed is written in its name (consumer: code of its
-- declaration, fk_user 0), simulated = 1 for the actions of a module in test mode (written, not done)
ALTER TABLE llx_inbox_action_log ADD COLUMN consumer varchar(64) DEFAULT NULL AFTER user_name;
ALTER TABLE llx_inbox_action_log ADD COLUMN simulated smallint DEFAULT 0 NOT NULL AFTER undone;
ALTER TABLE llx_inbox_action_log ADD INDEX idx_inbox_action_log_consumer (fk_account, consumer);
