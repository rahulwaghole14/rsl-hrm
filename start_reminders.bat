@echo off
title WhatsApp Meeting Reminders Daemon
echo ===================================================
echo WhatsApp Meeting Reminders Background Process
echo ===================================================
echo This window MUST remain open for 5-minute meeting 
echo reminders to be sent automatically. You can minimize 
echo it, but do not close it.
echo.
echo Press Ctrl+C to stop the process.
echo.

:loop
echo [%time%] Checking for upcoming meetings...
C:\xampp\php\php.exe c:\xampp\htdocs\company_calendor_1\cron_meeting_reminders.php
timeout /t 60 /nobreak > NUL
goto loop
