import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

declare const m: import('mithril').Static;

const t = (key: string, params?: Record<string, unknown>) => app.translator.trans('ernestdefoe-flarumverse.forum.page.' + key, params as any);

type Thread = {
  id: number;
  partner: number;
  forum: string | null;
  title: string;
  excerpt: string | null;
  author: string | null;
  tag: string | null;
  replies: number;
  url: string;
  postedAt: string;
};
type PartnerRef = { id: number; name: string; url: string; icon?: string | null; color?: string | null };

/** /flarumverse: the newest discussions from paired forums, newest first. */
export default class FlarumversePage extends Page {
  threads: Thread[] = [];
  partners: PartnerRef[] = [];
  partner = '0';
  more = false;
  loading = true;
  /** Partners whose favicon didn't load: they show their initial instead. */
  broken = new Set<number>();

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('flarumverse', extractText(t('title')));
    app.setTitle(extractText(t('title')));
    this.load(true);
  }

  load(reset: boolean) {
    this.loading = true;
    const offset = reset ? 0 : this.threads.length;

    app
      .request<{ threads: Thread[]; partners: PartnerRef[]; more: boolean }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/flarumverse/threads',
        params: { partner: this.partner, offset },
      })
      .then(
        (res) => {
          this.threads = reset ? res.threads : this.threads.concat(res.threads);
          this.partners = res.partners;
          this.more = res.more;
          this.loading = false;
          m.redraw();
        },
        () => {
          this.loading = false;
          m.redraw();
        }
      );
  }

  /** A forum's accent: its own brand colour, else a hue picked from its id so it keeps one. */
  static accent(id: number, color?: string | null): string {
    return color && /^#[0-9a-f]{6}$/i.test(color) ? color : `hsl(${(id * 137) % 360}, 58%, 46%)`;
  }

  static host(url: string | undefined): string {
    try {
      return url ? new URL(url).host.replace(/^www\./, '') : '';
    } catch (e) {
      return '';
    }
  }

  chip(id: string, label: Mithril.Children, accent?: string) {
    const active = this.partner === id;
    return (
      <button
        type="button"
        className={'FlarumverseChip' + (active ? ' FlarumverseChip--active' : '')}
        aria-pressed={active}
        style={accent ? { '--fv-accent': accent } : undefined}
        onclick={() => {
          if (active) return;
          this.partner = id;
          this.load(true);
        }}
      >
        {accent ? <span className="FlarumverseChip-dot" /> : null}
        {label}
      </button>
    );
  }

  view() {
    const partners = new Map(this.partners.map((p) => [p.id, p]));

    return (
      <PageStructure className="FlarumversePage" sidebar={() => <IndexSidebar />}>
        <header className="FlarumverseHero">
          <span className="FlarumverseHero-icon" aria-hidden="true">
            <Icon name="fas fa-satellite-dish" />
          </span>
          <div className="FlarumverseHero-text">
            <h2 className="FlarumverseHero-title">{t('title')}</h2>
            <p className="FlarumverseHero-intro">{t('intro', { forum: app.forum.attribute('title') })}</p>
          </div>
        </header>

        {this.partners.length > 1 ? (
          <div className="FlarumverseChips" role="group" aria-label={extractText(t('filter'))}>
            {this.chip('0', t('all_forums'))}
            {this.partners.map((p) => this.chip(String(p.id), p.name, FlarumversePage.accent(p.id, p.color)))}
          </div>
        ) : null}

        {this.threads.length === 0 && !this.loading ? <Placeholder text={t('empty')} /> : null}

        <ul className="FlarumverseList">
          {this.threads.map((thread) => {
            const partner = partners.get(thread.partner);
            const host = FlarumversePage.host(partner?.url || thread.url);
            const name = thread.forum || host;

            return (
              <li key={thread.id} className="FlarumverseItem" style={{ '--fv-accent': FlarumversePage.accent(thread.partner, partner?.color) }}>
                <a className="FlarumverseItem-link" href={thread.url} rel="noopener ugc">
                  <span
                    className={'FlarumverseItem-avatar' + (partner?.icon && !this.broken.has(thread.partner) ? ' FlarumverseItem-avatar--icon' : '')}
                    aria-hidden="true"
                  >
                    {partner?.icon && !this.broken.has(thread.partner) ? (
                      <img
                        src={partner.icon}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        referrerpolicy="no-referrer"
                        onerror={() => this.broken.add(thread.partner)}
                      />
                    ) : (
                      (name || '?').trim().charAt(0).toUpperCase()
                    )}
                  </span>
                  <span className="FlarumverseItem-body">
                    <span className="FlarumverseItem-source">
                      <span className="FlarumverseItem-forum">{name}</span>
                      {host && host !== name ? <span className="FlarumverseItem-host">{host}</span> : null}
                      <span className="FlarumverseItem-time">{humanTime(new Date(thread.postedAt))}</span>
                    </span>
                    <span className="FlarumverseItem-title">{thread.title}</span>
                    {thread.excerpt ? <span className="FlarumverseItem-excerpt">{thread.excerpt}</span> : null}
                    <span className="FlarumverseItem-meta">
                      {thread.author ? (
                        <span className="FlarumverseItem-pill">
                          <Icon name="far fa-user" /> {thread.author}
                        </span>
                      ) : null}
                      {thread.tag ? (
                        <span className="FlarumverseItem-pill">
                          <Icon name="fas fa-tag" /> {thread.tag}
                        </span>
                      ) : null}
                      <span className="FlarumverseItem-pill">
                        <Icon name="far fa-comment" /> {t('replies', { count: thread.replies })}
                      </span>
                      <span className="FlarumverseItem-open">
                        {t('open_on', { forum: name })} <Icon name="fas fa-arrow-up-right-from-square" />
                      </span>
                    </span>
                  </span>
                </a>
              </li>
            );
          })}
        </ul>

        {this.loading ? <LoadingIndicator /> : null}
        {this.more && !this.loading ? (
          <div className="FlarumversePage-more">
            <Button className="Button" onclick={() => this.load(false)}>
              {t('load_more')}
            </Button>
          </div>
        ) : null}
      </PageStructure>
    );
  }
}
