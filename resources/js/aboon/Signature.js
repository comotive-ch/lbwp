class Signature{
  constructor(canvas){
    this.canvas = canvas;
    this.canvas.width = this.canvas.clientWidth;
    this.canvas.height = this.canvas.clientHeight;

    this.ctx = this.canvas.getContext('2d');
    this.lines = [];
    this.setup();
  }

  setup(){
    this.setCanvasEvents();

    let form = document.querySelector('.signature-form');
    form.addEventListener('submit', (e) => {
      e.preventDefault();

      if(document.querySelector('input[name="signature"]').value !== ''){
        form.submit();
      }

      return false;
    })
  }

  setCanvasEvents(){
    let self = this;
    let drawing = false;
    let bounds = this.canvas.getBoundingClientRect();
    let signatureField = document.querySelector('input[name="signature"]');
    this.position = {
      previous : [0,0],
      current : [0,0]
    };

    // Clear and reset canvas on window resize
    window.addEventListener('resize', ()=>{
      let oldLines = this.lines;
      this.canvas.width = this.canvas.clientWidth;
      this.canvas.height = this.canvas.clientHeight;
      bounds = this.canvas.getBoundingClientRect();
      this.position.previous = [0,0];
      this.position.current = [0,0];
      this.ctx.clearRect(0,0,this.canvas.width, this.canvas.height);
      signatureField.value = '';

      // Redraw the lines
      oldLines.forEach(line => {
        this.ctx.beginPath();
        this.ctx.arc(line.x, line.y, line.r, 0, 2*Math.PI);
        this.ctx.fill();
        this.ctx.closePath();
      });

      // Update the drawing point
      let drawStartEvent = new MouseEvent('mousedown', {
        clientX: this.position.current[0] + bounds.left,
        clientY: this.position.current[1] + bounds.top
      });
      this.canvas.dispatchEvent(drawStartEvent);
    })

    // Start the signature
    this.canvas.addEventListener('mousedown', drawStart);
    this.canvas.addEventListener('touchstart', drawStart)
    function drawStart(e){
      drawing = true;
      bounds = self.canvas.getBoundingClientRect();
      self.position.previous = [self.position.current[0], self.position.current[1]];
      self.position.current = [e.clientX - bounds.left, e.clientY - bounds.top];
      self.drawPoint(self.position.current[0], self.position.current[1], 1.5);
    }

    // End the signature
    this.canvas.addEventListener('mouseup', drawStop);
    this.canvas.addEventListener('mouseout', drawStop);
    this.canvas.addEventListener('touchend', drawStop)
    function drawStop(e){
      drawing = false;
      self.ctx.closePath();
      signatureField.value = self.canvas.toDataURL();
    }

    // Drawing the signature
    this.canvas.addEventListener('mousemove', (e)=>{
      // Only draw if mousedown has been triggered and left button is pressed
      if(drawing && e.buttons === 1){
        this.draw(e, bounds);
      }
    });
    this.canvas.addEventListener('touchmove', (e) => {
      e.preventDefault();
      e.stopPropagation();
      let touchEvent = e.changedTouches[0];

      if(drawing) {
        this.draw(touchEvent, bounds);
      }
    })

    // Clear canvas
    document.querySelector('.signature-clear').addEventListener('click', ()=>{
      this.ctx.clearRect(0,0,this.canvas.width, this.canvas.height);
      this.lines = [];
      signatureField.value = '';
    })
  }

  /**
   * Draw on the canvas
   * @param event
   * @param bounds
   */
  draw(event, bounds){
    this.position.previous = [this.position.current[0], this.position.current[1]];
    this.position.current = [event.clientX - bounds.left, event.clientY - bounds.top];

    // Calculate the drawing points
    let cords = [];
    let x = this.position.current[0] - this.position.previous[0];
    let y = this.position.current[1] - this.position.previous[1];
    let distance = Math.sqrt(x*x + y*y);

    // Variate the line thickness
    let speed = 1.5 - distance / 100;
    let lineWidth = speed < 0.5 ? 0.5 : speed;

    for(let i = 1; i <= distance; i++){
      cords.push([
        this.position.previous[0] + x * i / distance,
        this.position.previous[1] + y * i / distance
      ]);
    }

    // Draw the points
    cords.forEach((cord) => {
      this.drawPoint(cord[0], cord[1], lineWidth);
    });
  }

  /**
   * Draw a arc (or point) on the canvas
   * @param x
   * @param y
   * @param r
   */
  drawPoint(x, y, r){
    this.ctx.beginPath();
    this.ctx.arc(x, y, r, 0, 2*Math.PI);
    this.ctx.fill();
    this.ctx.closePath();

    this.lines.push({x, y, r});
  }
}

document.addEventListener('readystatechange', (event) => {
  if (document.readyState === "complete") {
    const autoloadCanvas = document.querySelector('.signature-autosetup');
    if(autoloadCanvas !== null){
      new Signature(autoloadCanvas);
    }
  }
});