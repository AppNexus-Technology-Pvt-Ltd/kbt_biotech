import './bootstrap';
import { initAuthForms, initChecklists, initOtp, initPasswordToggles } from './features/auth';
import { initDashboard } from './features/dashboard';
import { initAssets } from './features/assets';
import { initAssetWorkflow } from './features/assets-workflow';
import { initAssetTools } from './features/assets-tools';
import { initInstallations } from './features/installations';
import { initOrganization, showFlash } from './features/organization';
import { initProfile } from './features/profile';
import { initShell } from './features/shell';

document.addEventListener('DOMContentLoaded', () => {
    initPasswordToggles();
    initChecklists();
    initAuthForms();
    initOtp();
    initShell();
    initDashboard();
    initProfile();
    initOrganization();
    initAssets();
    initAssetWorkflow();
    initAssetTools();
    initInstallations();
    showFlash();

    // Focus the first server-reported error so keyboard / screen-reader users land on it.
    document.querySelector('.is-invalid input')?.focus();
});
