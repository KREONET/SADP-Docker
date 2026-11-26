---
type: security
---
Cleared bootstrap root passwords from installer environment files after account creation

- A confirmed root account clears the password from .env and this install’s backup. If account creation is pending, the password stays available for a retry
