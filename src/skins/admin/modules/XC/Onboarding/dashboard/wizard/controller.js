define('dashboard/wizard', ['ready'], function() {
  var DashboardWizard = Object.extend({
    constructor: function(base) {
      var progress = $(base).find('.progress-circle');
      var widget = progress.circleProgress({
        value: progress.data('value'),
        size: 80,
        startAngle: Math.PI / 2,
        fill: 'white',
        emptyFill: 'rgba(255,255,255,0.1)'
      });

      widget.on('circle-animation-progress', function(e, draw, value) {
        var obj = $(this).data('circle-progress'),
          ctx = obj.ctx,
          size = obj.size,
          text = (100 * value).toFixed() + '%',
          fill = obj.arcFill;

        ctx.save();
        ctx.font = "normal 16px Open Sans";
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = fill;
        ctx.fillText(text, size / 2, size / 2);
        ctx.restore();
      });
    }
  });

  new DashboardWizard('#dashboard-wizard');

  return DashboardWizard;
});