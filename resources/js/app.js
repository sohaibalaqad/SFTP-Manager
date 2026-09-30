import Alpine from 'alpinejs';
import connectPage from './connect';
import fileManager from './file-manager';
import { toggleTheme } from './theme';

window.Alpine = Alpine;

Alpine.data('connectPage', connectPage);
Alpine.data('fileManager', fileManager);
Alpine.data('themeToggle', () => ({
    toggle: () => toggleTheme(),
}));

Alpine.start();
