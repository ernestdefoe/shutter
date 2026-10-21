import app from 'flarum/admin/app';

/*
 * Shutter's admin is its permissions and three settings; contests are run on
 * the forum, from their discussions.
 */
app.initializers.add('ernestdefoe-shutter', () => {
  const t = (key: string) => app.translator.trans(`ernestdefoe-shutter.admin.${key}`);

  app.registry
    .for('ernestdefoe-shutter')
    .registerSetting({ setting: 'ernestdefoe-shutter.show_nav', type: 'boolean', label: t('settings.show_nav') })
    .registerSetting({
      setting: 'ernestdefoe-shutter.max_mb',
      type: 'number',
      min: 1,
      max: 50,
      label: t('settings.max_mb'),
      help: t('settings.max_mb_help'),
    })
    .registerSetting({
      setting: 'ernestdefoe-shutter.max_edge',
      type: 'number',
      min: 1024,
      max: 4096,
      label: t('settings.max_edge'),
      help: t('settings.max_edge_help'),
    })
    .registerPermission({ icon: 'fas fa-upload', label: t('permissions.enter'), permission: 'shutter.enter' }, 'reply')
    .registerPermission({ icon: 'fas fa-heart', label: t('permissions.vote'), permission: 'shutter.vote' }, 'reply')
    .registerPermission({ icon: 'fas fa-camera-retro', label: t('permissions.manage'), permission: 'shutter.manage' }, 'moderate');
});
