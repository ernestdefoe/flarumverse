import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import lang from '../common/languageName';

declare const m: import('mithril').Static;

const t = (key: string, params?: Record<string, unknown>) => app.translator.trans('ernestdefoe-flarumverse.forum.directory.' + key, params as any);
const topicName = (key: string) => app.translator.trans('ernestdefoe-flarumverse.lib.topics.' + key);
const languageName = (code: string) => lang(code, app.translator.getLocale(), extractText(t('several_languages')));

type Forum = {
  id: number;
  url: string;
  name: string;
  description: string | null;
  logo: string | null;
  icon: string | null;
  logoBg: string | null;
  topic: string;
  language: string;
  members: number;
  discussions: number;
  lastActivity: string | null;
  latest: { title: string; url: string }[];
};

/** /forums: the Flarumverse directory (only on the forum that hosts it). */
export default class DirectoryPage extends Page {
  forums: Forum[] = [];
  topics: string[] = [];
  languages: string[] = [];
  topic = '';
  language = '';
  q = '';
  more = false;
  loading = true;
  timer: number | undefined;
  broken = new Set<number>();

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('flarumverse.directory', extractText(t('title')));
    app.setTitle(extractText(t('title')));
    this.load(true);
  }

  load(reset: boolean) {
    this.loading = true;
    app
      .request<{ forums: Forum[]; topics: string[]; languages: string[]; more: boolean }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/flarumverse/directory',
        params: { topic: this.topic, language: this.language, q: this.q, offset: reset ? 0 : this.forums.length },
      })
      .then(
        (res) => {
          this.forums = reset ? res.forums : this.forums.concat(res.forums);
          this.topics = res.topics;
          this.languages = res.languages;
          this.more = res.more;
        },
        () => {}
      )
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  topicOptions() {
    const options: Record<string, Mithril.Children> = { '': t('all_topics') };
    this.topics.forEach((k) => (options[k] = topicName(k)));
    return options;
  }

  languageOptions() {
    const options: Record<string, Mithril.Children> = { '': t('all_languages') };
    this.languages.forEach((k) => (options[k] = languageName(k)));
    return options;
  }

  filter(change: () => void) {
    change();
    this.load(true);
  }

  view() {
    return (
      <PageStructure className="FlarumverseDirectory" sidebar={() => <IndexSidebar />}>
        <p className="FlarumverseDirectory-intro">{t('intro')}</p>

        <div className="FlarumverseDirectory-filters">
          <input
            className="FormControl"
            type="search"
            aria-label={extractText(t('search'))}
            placeholder={extractText(t('search'))}
            value={this.q}
            oninput={(e: InputEvent) => {
              this.q = (e.target as HTMLInputElement).value;
              clearTimeout(this.timer);
              this.timer = window.setTimeout(() => this.load(true), 300);
            }}
          />
          <Select
            className="FlarumverseDirectory-select"
            options={this.topicOptions()}
            value={this.topic}
            onchange={(value: string) => this.filter(() => (this.topic = value))}
          />
          <Select
            className="FlarumverseDirectory-select"
            options={this.languageOptions()}
            value={this.language}
            onchange={(value: string) => this.filter(() => (this.language = value))}
          />
        </div>

        {!this.loading && !this.forums.length ? <Placeholder text={t('empty')} /> : null}

        <ul className="FlarumverseDirectory-grid">
          {this.forums.map((f) => (
            <li key={f.id} className="FlarumverseForum">
              <a
                className={'FlarumverseForum-banner is-' + (f.logoBg?.startsWith('#') ? 'brand' : f.logoBg || 'light')}
                href={f.url}
                rel="noopener ugc"
                tabindex="-1"
                aria-hidden="true"
                style={f.logoBg?.startsWith('#') ? { background: f.logoBg } : undefined}
              >
                {(f.logo || f.icon) && !this.broken.has(f.id) ? (
                  <img
                    src={f.logo || f.icon}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    referrerpolicy="no-referrer"
                    className={f.logo ? 'FlarumverseForum-logo' : 'FlarumverseForum-icon'}
                    onerror={() => {
                      this.broken.add(f.id);
                      m.redraw();
                    }}
                  />
                ) : (
                  <span className="FlarumverseForum-initial">{Array.from(f.name.trim())[0]?.toUpperCase()}</span>
                )}
              </a>
              <div className="FlarumverseForum-body">
                <a className="FlarumverseForum-name" href={f.url} rel="noopener ugc">
                  {f.name}
                </a>
                <span className="FlarumverseForum-host">{f.url.replace(/^https:\/\//, '')}</span>
                {f.description ? <p className="FlarumverseForum-description">{f.description}</p> : null}
                <div className="FlarumverseForum-chips">
                  <span>{topicName(f.topic)}</span>
                  <span>{languageName(f.language)}</span>
                </div>
                <div className="FlarumverseForum-stats">
                  <span>
                    <Icon name="fas fa-user" /> {t('members', { count: f.members })}
                  </span>
                  <span>
                    <Icon name="far fa-comments" /> {t('discussions', { count: f.discussions })}
                  </span>
                  {f.lastActivity ? (
                    <span>
                      <Icon name="far fa-clock" /> {t('active', { ago: humanTime(new Date(f.lastActivity)) })}
                    </span>
                  ) : null}
                </div>
                {f.latest.length ? (
                  <div className="FlarumverseForum-latest">
                    <span className="FlarumverseForum-latestLabel">{t('latest')}</span>
                    <ul>
                      {f.latest.map((d) => (
                        <li>
                          <a href={d.url} rel="noopener ugc">
                            {d.title}
                          </a>
                        </li>
                      ))}
                    </ul>
                  </div>
                ) : null}
              </div>
              <div className="FlarumverseForum-footer">
                <a className="Button Button--primary FlarumverseForum-visit" href={f.url} rel="noopener ugc">
                  {t('visit')}
                </a>
              </div>
            </li>
          ))}
        </ul>

        {this.loading ? <LoadingIndicator /> : null}
        {this.more && !this.loading ? (
          <div className="FlarumversePage-more">
            <Button className="Button" onclick={() => this.load(false)}>
              {t('load_more')}
            </Button>
          </div>
        ) : null}

        <p className="FlarumverseDirectory-cta">{t('list_yours')}</p>
      </PageStructure>
    );
  }
}
