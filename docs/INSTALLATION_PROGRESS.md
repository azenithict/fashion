# Operator-reported cPanel installation progress

The owner is executing pinned, reviewed commands directly in cPanel Terminal. Production credentials have not been provided to the agent or committed.

- Known extracted-site ZIP moved into `/home/isp85/fashion-private/quarantine`, outside the document root.
- Dedicated `isp85_fashion` database and user created; connection initially verified with zero tables.
- CI artifact for PR3 privately staged and manifest verified: `b50edb91830b3f8a4ef90b1062380f2cff5a1259`,163 files. This is the tested PR merge commit corresponding to source `6f00983e94e44f691425489dd98a4d434f082868`.
- Private database configuration created with0600 permissions and owner-only storage directories. Connection defaults match the actual successful cPanel CLI test.
- Migrations001–005 applied on cPanel. Owner's read-only check reports37 tables,28 triggers and5 migration records.
- Administrator created and then rotated to a different password using the private operator tool; prior sessions invalidated. No passwords recorded here.
- Sender selected by owner: `sales@azenith.ca`. cPanel reports authenticated SSL/TLS SMTP `mail.azenith.ca:465`. Real authentication and received-email verification remain PENDING.

No public activation, payment activation or production-readiness approval has occurred. Mail delivery, least-privilege runtime DB access, actual web/API acceptance, host backup/restore/rollback, commercial/legal/supplier/partner approvals and the engineering gaps in PRODUCTION_READINESS.md remain open. These installation facts supersede older 'database not created' notes, while the original strict definition of done remains unmet.
