@echo off
echo ==============================================
echo Meeting Reminder Background Service
echo ==============================================
echo Keep this window open to automatically send
echo WhatsApp reminders 5 minutes before meetings.
echo Press Ctrl+C to stop.
echo ==============================================

:loop
echo [%date% %time%] Checking for upcoming meetings...
c:\xampp\php\php.exe cron_meeting_reminders.php
timeout /t 60 /nobreak >nul
goto loop
