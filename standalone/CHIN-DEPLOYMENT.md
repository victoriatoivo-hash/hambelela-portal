# Deploy MIV Shipping to chin

This workflow updates the existing standalone installation at https://chin.hambelelaorganic.com.
It does not install a new account or change the database. Only index.php, api.php, and
the MIV JavaScript/CSS files are uploaded. Other runtime changes require separate review.

## One-time connection
1. In FastComet cPanel, create a dedicated FTP account with its directory set to
   /home/hambele1/chin.hambelelaorganic.com. Do not use the portal FTP account.
2. In that directory, create a text file named .chin-deploy-target containing exactly:
   chin.hambelelaorganic.com
3. In GitHub repository Settings > Secrets and variables > Actions, add repository secrets:
   - CHIN_FTP_SERVER: the server hostname from cPanel's FTP configuration (must match its TLS certificate).
   - CHIN_FTP_USERNAME: the full username of the new FTP account.
   - CHIN_FTP_PASSWORD: its password.
   Enter credentials directly in GitHub, never in chat or source files.
4. Open Actions > Deploy standalone MIV to chin > Run workflow. Choose main and mode check.
   This tests login, destination identity and file access without uploading.
5. After the check succeeds, run the same workflow with mode deploy.
6. Open the app, sign in, check Delivery only and the bottom total bar.
7. To deploy future app updates automatically, add the repository Actions variable
   CHIN_DEPLOY_ENABLED with value true.

Automatic deployment is disabled until that variable is set. Tests still run on pull requests
and relevant main pushes. Manual runs can deploy only main. Portal deployment workflows
and credentials are not used.

## Failure handling
TLS certificate validation is mandatory; there is no plain FTP fallback. If connection
verification fails, confirm the FTP hostname with FastComet. Do not disable TLS checks.
A missing destination marker or a changed bootstrap stops the upload.
All four existing files are read before uploading. Failed uploads attempt to restore every
touched file and verify restoration; an incomplete rollback fails explicitly.
An interrupted runner may prevent rollback; restore the four files from the previous
approved bundle in that case. Deployment is per-file, not an atomic whole-site switch.
