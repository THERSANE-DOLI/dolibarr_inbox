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

-- 1.144.0: a signature is believed again only on a mail received at most a few minutes away from its first reception
-- (the copies of one mail); its Message-ID no longer tells a copy from a replay, since the sender writes it
ALTER TABLE llx_inbox_source_signature ADD COLUMN date_received datetime DEFAULT NULL AFTER date_signature;
-- Rows kept by 1.143.0: the first reception of their Message-ID in the index (else the time of the signature is used)
UPDATE llx_inbox_source_signature SET date_received = (SELECT MIN(m.date_received) FROM llx_inbox_mail as m WHERE m.message_id = llx_inbox_source_signature.mail_key) WHERE date_received IS NULL AND mail_key <> '' AND mail_key NOT LIKE 'received:%';
