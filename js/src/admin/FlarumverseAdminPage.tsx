import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import lang from '../common/languageName';

declare const m: import('mithril').Static;

const t = (key: string, params?: Record<string, unknown>) => app.translator.trans('ernestdefoe-flarumverse.admin.' + key, params as any);

type Partner = {
  id: number;
  url: string;
  name: string;
  description: string | null;
  status: 'incoming' | 'outgoing' | 'paired' | 'declined' | 'refused';
  threads: number;
  pairedAt: string | null;
  updatedAt: string;
  lastError: string | null;
};
type DirectoryState = {
  host: string;
  isHost: boolean;
  listed: boolean;
  topic: string;
  language: string;
  status: '' | 'pending' | 'approved' | 'rejected' | 'removed';
  logoBg: 'light' | 'dark' | 'brand';
  logo: string | null;
  brand: string | null;
  topics: string[];
  languages: string[];
};
type Listing = {
  id: number;
  url: string;
  name: string;
  description: string | null;
  topic: string;
  language: string;
  status: 'pending' | 'approved' | 'rejected';
  members: number;
  discussions: number;
  unreachable: boolean;
  updatedAt: string;
};
type State = { url: string; max: number; partners: Partner[]; suggestions: string[]; directory: DirectoryState };

/** "en" → "English", in the admin's own language. */
const languageName = (code: string) => lang(code, app.translator.getLocale(), extractText(t('directory.several_languages')));

/**
 * Flarumverse's admin page: this forum's address, pairing with a forum (and the
 * suggested first partner), requests to approve, partners, and requests waiting.
 */
export default class FlarumverseAdminPage extends ExtensionPage {
  data: State | null = null;
  address = '';
  busy: string | null = null;
  error: string | null = null;
  notice: string | null = null;
  copied = false;
  dir: { listed: boolean; topic: string; language: string; logoBg: string } | null = null;
  dirBusy = false;
  dirError: string | null = null;
  dirSaved = false;
  listings: Listing[] | null = null;

  oninit(vnode: Mithril.Vnode<any, any>) {
    super.oninit(vnode);
    this.call('GET');
  }

  call(method: string, path = '', body?: object): Promise<void> {
    return app
      .request<State>({ method, url: app.forum.attribute('apiUrl') + '/flarumverse/partners' + path, body, errorHandler: () => {} } as any)
      .then(
        (state) => {
          if (state) this.data = state;
          if (state && !this.dir)
            this.dir = {
              listed: state.directory.listed,
              topic: state.directory.topic,
              language: state.directory.language,
              logoBg: state.directory.logoBg,
            };
          if (state?.directory.isHost && this.listings === null) this.loadListings();
          return method === 'DELETE' ? this.call('GET') : undefined;
        },
        (e: any) => {
          this.error = e?.response?.errors?.[0]?.detail || extractText(app.translator.trans('core.lib.error.generic_message'));
        }
      )
      .finally(() => {
        this.busy = null;
        m.redraw();
      });
  }

  pair(url: string) {
    this.busy = 'pair:' + url;
    this.error = this.notice = null;
    this.call('POST', '', { url }).then(() => {
      if (this.error) return;
      const added = this.data?.partners.find((p) => p.url.replace(/^https:\/\//, '') === url.replace(/^https?:\/\//, '').replace(/\/+$/, ''));
      this.notice = extractText(t('pair.sent', { name: added?.name || url }));
      this.address = '';
    });
  }

  act(partner: Partner, action: 'approve' | 'decline' | 'remove') {
    if (action === 'remove' && partner.status === 'paired' && !confirm(extractText(t('partner.confirm_remove', { name: partner.name })))) return;
    this.busy = action + ':' + partner.id;
    this.error = this.notice = null;
    action === 'remove' ? this.call('DELETE', '/' + partner.id) : this.call('PATCH', '/' + partner.id, { action });
  }

  content() {
    const s = this.data;
    if (!s)
      return (
        <div className="container">
          <LoadingIndicator />
        </div>
      );

    const by = (status: Partner['status']) => s.partners.filter((p) => p.status === status);
    const paired = by('paired');

    return (
      <div className="FlarumverseAdmin container">
        <div className="FlarumverseAdmin-address">
          <span>{t('your_address')}</span> <code>{s.url}</code>{' '}
          <Button
            className="Button Button--text"
            icon={this.copied ? 'fas fa-check' : 'far fa-copy'}
            onclick={() => navigator.clipboard?.writeText(s.url).then(() => ((this.copied = true), m.redraw()))}
          >
            {this.copied ? t('copied') : t('copy')}
          </Button>
        </div>

        <section className="FlarumverseAdmin-section">
          <h3>{t('pair.heading')}</h3>
          <form
            className="FlarumverseAdmin-pair"
            onsubmit={(e: Event) => {
              e.preventDefault();
              if (this.address.trim()) this.pair(this.address.trim());
            }}
          >
            <label className="sr-only" for="FlarumverseAdmin-address">
              {t('pair.label')}
            </label>
            <input
              id="FlarumverseAdmin-address"
              className="FormControl"
              type="text"
              inputmode="url"
              autocomplete="off"
              placeholder={extractText(t('pair.placeholder'))}
              value={this.address}
              oninput={(e: InputEvent) => {
                this.address = (e.target as HTMLInputElement).value;
              }}
            />
            <Button
              className="Button Button--primary"
              type="submit"
              loading={this.busy?.startsWith('pair:') && !s.suggestions.includes(this.busy.slice(5))}
              disabled={!this.address.trim()}
            >
              {t('pair.button')}
            </Button>
          </form>
          {this.error ? <p className="FlarumverseAdmin-error">{this.error}</p> : null}
          {this.notice ? <p className="FlarumverseAdmin-notice">{this.notice}</p> : null}

          {s.suggestions.length ? (
            <div className="FlarumverseAdmin-suggestions">
              <h4>{t('suggestions.heading')}</h4>
              <p className="helpText">{t('suggestions.help')}</p>
              {s.suggestions.map((url) => (
                <div className="FlarumversePartner" key={url}>
                  <div className="FlarumversePartner-info">
                    <strong>{url.replace(/^https:\/\//, '')}</strong>
                  </div>
                  <div className="FlarumversePartner-actions">
                    <Button className="Button" loading={this.busy === 'pair:' + url} onclick={() => this.pair(url)}>
                      {t('pair.button')}
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          ) : null}
        </section>

        {this.list(t('sections.requests'), by('incoming'), (p) => [
          <Button className="Button Button--primary" loading={this.busy === 'approve:' + p.id} onclick={() => this.act(p, 'approve')}>
            {t('partner.approve')}
          </Button>,
          <Button className="Button" loading={this.busy === 'decline:' + p.id} onclick={() => this.act(p, 'decline')}>
            {t('partner.decline')}
          </Button>,
        ])}

        <section className="FlarumverseAdmin-section">
          <h3>{t('sections.paired', { count: paired.length, max: s.max })}</h3>
          {paired.length ? this.rows(paired, (p) => [this.removeButton(p, 'remove')]) : <p className="helpText">{t('sections.none_paired')}</p>}
        </section>

        {this.list(t('sections.waiting'), by('outgoing'), (p) => [this.removeButton(p, 'cancel')])}
        {this.list(t('sections.refused'), by('refused'), (p) => [this.removeButton(p, 'forget')])}
        {this.list(t('sections.declined'), by('declined'), (p) => [this.removeButton(p, 'forget')])}

        {this.directorySection(s.directory)}
        {s.directory.isHost ? this.listingsSection() : null}

        <section className="FlarumverseAdmin-section">
          <h3>{t('how.heading')}</h3>
          <ul className="FlarumverseAdmin-how">
            <li>{t('how.items.shared')}</li>
            <li>{t('how.items.sent')}</li>
            <li>{t('how.items.refresh')}</li>
          </ul>
        </section>
      </div>
    );
  }

  directorySection(d: DirectoryState) {
    const f = this.dir!;
    const host = d.host.replace(/^https:\/\//, '');

    return (
      <section className="FlarumverseAdmin-section FlarumverseAdmin-directory">
        <h3>{t('directory.heading')}</h3>
        <p className="helpText">{t('directory.help', { host })}</p>
        <label className="checkbox">
          <input
            type="checkbox"
            checked={f.listed}
            onchange={(e: Event) => {
              f.listed = (e.target as HTMLInputElement).checked;
              this.dirSaved = false;
            }}
          />{' '}
          {t('directory.listed')}
        </label>
        {f.listed ? (
          <div className="FlarumverseAdmin-pickers">
            <label>
              <span>{t('directory.topic')}</span>
              <select
                className="FormControl"
                value={f.topic}
                onchange={(e: Event) => {
                  f.topic = (e.target as HTMLSelectElement).value;
                  this.dirSaved = false;
                }}
              >
                <option value="">{t('directory.pick')}</option>
                {d.topics.map((k) => (
                  <option value={k}>{app.translator.trans('ernestdefoe-flarumverse.lib.topics.' + k)}</option>
                ))}
              </select>
            </label>
            <label>
              <span>{t('directory.language')}</span>
              <select
                className="FormControl"
                value={f.language}
                onchange={(e: Event) => {
                  f.language = (e.target as HTMLSelectElement).value;
                  this.dirSaved = false;
                }}
              >
                <option value="">{t('directory.pick')}</option>
                {d.languages
                  .map((k) => [k, languageName(k)])
                  .sort((a, b) => (a[0] === 'mul' ? 1 : b[0] === 'mul' ? -1 : a[1].localeCompare(b[1])))
                  .map(([k, name]) => (
                    <option value={k}>{name}</option>
                  ))}
              </select>
            </label>
            <label>
              <span>{t('directory.logo_bg')}</span>
              <select
                className="FormControl"
                value={f.logoBg}
                onchange={(e: Event) => {
                  f.logoBg = (e.target as HTMLSelectElement).value;
                  this.dirSaved = false;
                }}
              >
                <option value="light">{t('directory.logo_bg_light')}</option>
                <option value="dark">{t('directory.logo_bg_dark')}</option>
                {d.brand ? <option value="brand">{t('directory.logo_bg_brand')}</option> : null}
              </select>
            </label>
            {d.logo ? (
              <div className="FlarumverseAdmin-logoPreview">
                <p className="helpText">{t('directory.logo_bg_help')}</p>
                <div
                  className={'FlarumverseAdmin-logoBand is-' + f.logoBg}
                  style={f.logoBg === 'brand' && d.brand ? { background: d.brand } : undefined}
                >
                  <img src={d.logo} alt="" />
                </div>
              </div>
            ) : null}
          </div>
        ) : null}
        <div className="FlarumverseAdmin-dirSave">
          <Button className="Button Button--primary" loading={this.dirBusy} onclick={() => this.saveDirectory()}>
            {t('directory.save')}
          </Button>
          {this.dirSaved ? <span>{t('directory.saved')}</span> : null}
          {d.status && d.listed ? <span className={'FlarumverseAdmin-dirStatus is-' + d.status}>{t('directory.status.' + d.status)}</span> : null}
        </div>
        {this.dirError ? <p className="FlarumverseAdmin-error">{this.dirError}</p> : null}
      </section>
    );
  }

  saveDirectory() {
    const f = this.dir!;
    this.dirBusy = true;
    this.dirError = null;
    app
      .request<State>({ method: 'PUT', url: app.forum.attribute('apiUrl') + '/flarumverse/partners', body: f, errorHandler: () => {} } as any)
      .then(
        (state) => {
          this.data = state;
          this.dir = {
            listed: state.directory.listed,
            topic: state.directory.topic,
            language: state.directory.language,
            logoBg: state.directory.logoBg,
          };
          this.dirSaved = true;
          if (state.directory.isHost) this.loadListings();
        },
        (e: any) => {
          this.dirError = e?.response?.errors?.[0]?.detail || extractText(app.translator.trans('core.lib.error.generic_message'));
        }
      )
      .finally(() => {
        this.dirBusy = false;
        m.redraw();
      });
  }

  loadListings(method = 'GET', id?: number, body?: object) {
    this.busy = id ? method + ':listing:' + id : this.busy;
    app
      .request<{ listings: Listing[] }>({
        method,
        url: app.forum.attribute('apiUrl') + '/flarumverse/directory/listings' + (id ? '/' + id : ''),
        body,
      })
      .then((res) => {
        this.listings = res.listings;
      })
      .finally(() => {
        this.busy = null;
        m.redraw();
      });
  }

  listingsSection() {
    const all = this.listings || [];
    const group = (status: Listing['status']) => all.filter((l) => l.status === status);
    const row = (l: Listing, actions: Mithril.Children[]) => (
      <div className="FlarumversePartner" key={l.id}>
        <div className="FlarumversePartner-info">
          <a href={l.url} target="_blank" rel="noopener noreferrer">
            <strong>{l.name}</strong>
          </a>
          <span className="FlarumversePartner-url">{l.url.replace(/^https:\/\//, '')}</span>
          {l.description ? <span className="FlarumversePartner-description">{l.description}</span> : null}
          <span className="FlarumversePartner-meta">
            {app.translator.trans('ernestdefoe-flarumverse.lib.topics.' + l.topic)} · {languageName(l.language)} ·{' '}
            {t('listings.stats', { members: l.members, discussions: l.discussions })}
          </span>
          {l.unreachable ? <span className="FlarumversePartner-error">{t('listings.unreachable')}</span> : null}
        </div>
        <div className="FlarumversePartner-actions">{actions}</div>
      </div>
    );
    const btn = (l: Listing, label: string, method: string, body?: object, primary = false) => (
      <Button
        className={'Button' + (primary ? ' Button--primary' : '')}
        loading={this.busy === method + ':listing:' + l.id}
        onclick={() => this.loadListings(method, l.id, body)}
      >
        {t('listings.' + label)}
      </Button>
    );
    const block = (label: string, items: Listing[], actions: (l: Listing) => Mithril.Children[]) =>
      items.length ? (
        <div className="FlarumverseAdmin-group">
          <h4>{t('listings.' + label)}</h4>
          {items.map((l) => row(l, actions(l)))}
        </div>
      ) : null;

    return (
      <section className="FlarumverseAdmin-section">
        <h3>{t('listings.heading')}</h3>
        <p className="helpText">
          {t('listings.help')}{' '}
          <a href={app.forum.attribute('baseUrl') + '/forums'} target="_blank" rel="noopener">
            {t('listings.view')}
          </a>
        </p>
        {this.listings === null ? <LoadingIndicator /> : null}
        {this.listings && !all.length ? <p className="helpText">{t('listings.none')}</p> : null}
        {block('pending', group('pending'), (l) => [
          btn(l, 'approve', 'PATCH', { action: 'approve' }, true),
          btn(l, 'reject', 'PATCH', { action: 'reject' }),
        ])}
        {block('approved', group('approved'), (l) => [btn(l, 'remove', 'DELETE')])}
        {block('rejected', group('rejected'), (l) => [btn(l, 'remove', 'DELETE')])}
      </section>
    );
  }

  removeButton(p: Partner, label: 'remove' | 'cancel' | 'forget') {
    return (
      <Button className="Button" loading={this.busy === 'remove:' + p.id} onclick={() => this.act(p, 'remove')}>
        {t('partner.' + label)}
      </Button>
    );
  }

  list(heading: Mithril.Children, partners: Partner[], actions: (p: Partner) => Mithril.Children[]) {
    if (!partners.length) return null;

    return (
      <section className="FlarumverseAdmin-section">
        <h3>{heading}</h3>
        {this.rows(partners, actions)}
      </section>
    );
  }

  rows(partners: Partner[], actions: (p: Partner) => Mithril.Children[]) {
    return partners.map((p) => (
      <div className="FlarumversePartner" key={p.id}>
        <div className="FlarumversePartner-info">
          <a href={p.url} target="_blank" rel="noopener noreferrer">
            <strong>{p.name}</strong>
          </a>
          <span className="FlarumversePartner-url">{p.url.replace(/^https:\/\//, '')}</span>
          {p.description ? <span className="FlarumversePartner-description">{p.description}</span> : null}
          <span className="FlarumversePartner-meta">
            {p.status === 'paired'
              ? [t('partner.paired_since', { ago: humanTime(new Date(p.pairedAt!)) }), ' · ', t('partner.threads', { count: p.threads })]
              : t('partner.asked', { ago: humanTime(new Date(p.updatedAt)) })}
          </span>
          {p.lastError ? <span className="FlarumversePartner-error">{t('partner.unreachable', { error: p.lastError })}</span> : null}
        </div>
        <div className="FlarumversePartner-actions">{actions(p)}</div>
      </div>
    ));
  }
}
