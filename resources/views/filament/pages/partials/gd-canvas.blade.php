{{-- Preview canvas. Needs: $html. Parent x-data = gdPreviewPage(). --}}
<style wire:ignore x-text="activeCss"></style>
<div class="gd-canvas" x-ref="canvas">
    <div class="gd-holder">
        <div x-ref="doc" id="gd-doc"
             x-on:mouseup="onDocUp($event)"
             x-on:keyup="onDocUp($event)"
             x-on:touchend="setTimeout(() => onDocUp($event), 50)"
             x-on:beforeinput="onBeforeInput($event)"
             x-on:input="onEditInput($event)">{!! $html !!}</div>
    </div>
</div>
