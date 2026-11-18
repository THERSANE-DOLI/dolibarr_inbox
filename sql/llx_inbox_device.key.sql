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

-- One device per entity for the same key of a browser (its storage is the one of the site, not of the entity)
ALTER TABLE llx_inbox_device ADD UNIQUE INDEX uk_inbox_device_entity_key (entity, fk_user, fingerprint);
ALTER TABLE llx_inbox_device ADD INDEX idx_inbox_device_user (entity, fk_user, status);
