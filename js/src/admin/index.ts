import app from 'flarum/admin/app';
import FlarumverseAdminPage from './FlarumverseAdminPage';

app.initializers.add('ernestdefoe/flarumverse', () => {
  app.registry.for('ernestdefoe-flarumverse').registerPage(FlarumverseAdminPage);
});
