import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import FlarumversePage from './FlarumversePage';
import DirectoryPage from './DirectoryPage';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

const t = (key: string) => app.translator.trans('ernestdefoe-flarumverse.forum.' + key);

app.initializers.add('ernestdefoe/flarumverse', () => {
  // Small enough (~1 KB gz) that a lazy chunk's loader would cost more than it saves.
  app.routes.flarumverse = { path: '/flarumverse', component: FlarumversePage };
  app.routes['flarumverse.directory'] = { path: '/forums', component: DirectoryPage };

  // Shown once the forum has a partner; the number rides on the forum payload.
  extend(IndexSidebar.prototype, 'navItems', function (items: ItemList<Mithril.Children>) {
    if (app.forum.attribute('flarumverseDirectory')) {
      items.add(
        'flarumverseDirectory',
        <LinkButton href={app.route('flarumverse.directory')} icon="fas fa-compass">
          {t('directory.nav')}
        </LinkButton>,
        79
      );
    }
    if ((app.forum.attribute<number>('flarumversePartners') || 0) < 1) return;

    items.add(
      'flarumverse',
      <LinkButton href={app.route('flarumverse')} icon="fas fa-circle-nodes">
        {t('nav')}
      </LinkButton>,
      80
    );
  });
});
