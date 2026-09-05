<?php
/**
 * Hybrid SMTP Configuration
 * 
 * - Main SMTP account: used to send all emails
 * - Dynamic "from" addresses: pulled from user database
 * - Allows emails to appear from different users/departments while using one SMTP account
 * 
 * Keep this file out of version control if it contains real credentials.
 * 
 * Gmail example:
 * - host: smtp.gmail.com
 * - port: 587
 * - encryption: tls
 * - username: your Gmail address (app password required)
 * - password: Gmail App Password (not your normal password)
 */
return [
    // Main SMTP connection (system-wide)
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'username' => 'alegadojohnlester@gmail.com',
    'password' => 'txci qzmo yfse zfen',
    'encryption' => 'tls',
    
    // Default/fallback sender
    'default_from_address' => 'alegadojohnlester@gmail.com',
    'default_from_name' => 'Capstone System',
    
    // Enable dynamic user emails as "from" address
    'use_user_email_as_from' => true,
];

