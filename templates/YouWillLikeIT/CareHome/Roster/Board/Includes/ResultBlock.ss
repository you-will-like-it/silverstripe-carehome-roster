<div class="rs-result" x-show="result.blocks.length || result.warnings.length || result.info.length || ctx.askReason">
    <ul class="rs-blocks" x-show="result.blocks.length">
        <template x-for="m in result.blocks"><li x-text="m"></li></template>
    </ul>
    <ul class="rs-warnings" x-show="result.warnings.length">
        <template x-for="m in result.warnings"><li x-text="m"></li></template>
    </ul>
    <ul class="rs-info" x-show="result.info.length">
        <template x-for="m in result.info"><li x-text="m"></li></template>
    </ul>
</div>
<div class="rs-form-block" x-show="ctx.askReason">
    <label class="rs-label" for="rs-reason"><%t YouWillLikeIT\\CareHome\\Roster\\Board.REASON "Razlog odstupanja (obavezno, upisuje se u audit log)" %></label>
    <textarea id="rs-reason" class="rs-input" rows="2" x-model="ctx.reason"></textarea>
</div>
